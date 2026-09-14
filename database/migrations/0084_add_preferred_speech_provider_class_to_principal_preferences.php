<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Two pieces of the speech-preference seam that close the legacy
 * speech-storage path:
 *
 *   1. Replace `principal_preferences.preferred_speech_provider_class`
 *      (string FQCN) with `preferred_speech_config_id` (FK to
 *      `speech_provider_configurations(id)` ON DELETE SET NULL).
 *
 *      The legacy column stored the FQCN of the registered STT class
 *      (a string). Two reasons it has to go away now that speech rows
 *      live in `speech_provider_configurations`:
 *
 *        1. The class string can't represent a specific config —
 *           global vs per-user vs per-group, two operators could each
 *           have a different config for the same class, etc. Now that
 *           speech configs live in one table, the FK is the natural
 *           shape.
 *
 *        2. The registry's tier 2 / 3 cascade needs to validate the
 *           pointed-at row still exists (a stale pointer must fall
 *           through, mirroring the LLM side). A class-name pointer
 *           gives the cascade nothing to validate against
 *           per-operator; an FK gives it the row to look up.
 *
 *      Why `ON DELETE SET NULL`:
 *
 *        When the operator deletes a speech config (the destroy
 *        endpoint routes through `detachConfigurationReferencesStatic`
 *        first, but the FK must also defend against admin SQL or
 *        other callers bypassing the persistence layer), the
 *        preference pointer is NULLed rather than cascade-deleting
 *        the entire principal preference row. The registry treats
 *        NULL as "no preference" and falls through to the next tier.
 *
 *      Idempotency:
 *
 *        `hasColumn` + `foreignKeyExists` gates on every step. A
 *        re-run after a partial failure or against a clean DB is a
 *        no-op.
 *
 *   2. Drop `agent_tool_overrides` rows whose `tool_class` is a
 *      registered STT class.
 *
 *      Why these rows are deleted:
 *
 *        Per-agent speech overrides are intentionally out of scope
 *        for the new cascade. The operator's PR-feedback was
 *        explicit: configuring a per-agent STT class is excessive
 *        granularity for STT (one operator's preferences + a global
 *        default covers every realistic use case). The new
 *        {@see \Spora\Speech\SpeechToTextRegistry::resolveEffectiveClassWithSource()}
 *        ignores `agent_tool_overrides` entirely — the per-agent
 *        tier from the previous PR is gone.
 *
 *        After migration 0083's data backfill every existing
 *        override has a corresponding row in the new
 *        `speech_provider_configurations` table (when the operator's
 *        principal permits it). Keeping the override rows around
 *        would (a) leak the old tier into any future debug dump
 *        that scans the table, and (b) confuse operators who add a
 *        global default and then wonder why a specific agent still
 *        transcribes with a different key — the override shadow
 *        would be silently bypassed by the new code but still
 *        visible in the existing per-agent override UI.
 *
 *      Other tools' overrides (email, calendar, weather, search, …)
 *      are NOT touched — speech is a narrow scope here, not a sweep.
 *
 *      Plugin-contributed STT classes (e.g. MuseTranscribeProvider)
 *      are filtered the same way as the backfill migration 0083:
 *      only the core-shipped `OpenAiCompatibleTranscriber` is
 *      enumerated, because the CLI boot at migration time has no
 *      plugin loader. Operators running the spora-plugin-muse
 *      plugin will need to manually re-create their STT configs via
 *      the new `POST /api/v1/speech/provider-configs` endpoint, and
 *      the legacy `agent_tool_overrides` rows for the plugin's class
 *      are not touched here (they preserve any non-speech-row that
 *      might also be using the same agent_id).
 *
 * Migration ordering:
 *
 *   This migration (0084) MUST run AFTER 0067 (the principals rename),
 *   AFTER 0082 (the unique-constraint restore on `tool_user_settings`
 *   + the `speech_provider_configurations` table creation), and AFTER
 *   0083 (the data migration + `is_default` columns) because the
 *   preference FK references the new table that 0082 created and 0083
 *   backfilled.
 */
return new class extends Migration {
    use Spora\Core\Database\MigrationHelpers;

    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('principal_preferences')) {
            $this->swapPreferredSpeechProviderToFk($schema);
        }

        $this->removeSpeechAgentToolOverrides($schema);
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('principal_preferences')) {
            return;
        }

        if ($this->foreignKeyExists('principal_preferences', 'fk_principal_preferences_preferred_speech_config_id')) {
            $schema->table('principal_preferences', static function (Blueprint $table): void {
                $table->dropForeign('fk_principal_preferences_preferred_speech_config_id');
            });
        }

        if ($schema->hasColumn('principal_preferences', 'preferred_speech_config_id')) {
            $schema->table('principal_preferences', static function (Blueprint $table): void {
                $table->dropColumn('preferred_speech_config_id');
            });
        }

        // No-op for the agent_tool_overrides sweep — we don't know
        // what the original settings were, and recreating them with
        // empty settings would be misleading.
    }

    private function swapPreferredSpeechProviderToFk(\Illuminate\Database\Schema\Builder $schema): void
    {
        if ($schema->hasColumn('principal_preferences', 'preferred_speech_config_id')) {
            // The FK column already landed (forward-only re-run).
            return;
        }

        // The legacy class-string column is intentionally NOT
        // back-filled into the FK — the cascade tier-2/3 walk would
        // look up the pointed-at row by ID, not class, and any
        // unconverted row would silently fall through to tier-4. The
        // legacy column is dropped here; operators with a preference
        // set must re-select via the new
        // PUT /api/v1/speech/preference endpoint after upgrade. The
        // operator-facing UX surfaces this as part of the broader
        // speech-config migration notice.
        if ($schema->hasColumn('principal_preferences', 'preferred_speech_provider_class')) {
            $schema->table('principal_preferences', static function (Blueprint $table): void {
                $table->dropColumn('preferred_speech_provider_class');
            });
        }

        $schema->table('principal_preferences', static function (Blueprint $table): void {
            $table->unsignedBigInteger('preferred_speech_config_id')->nullable()->after('preferred_llm_config_id');
        });

        if (!$this->foreignKeyExists('principal_preferences', 'fk_principal_preferences_preferred_speech_config_id')) {
            $schema->table('principal_preferences', static function (Blueprint $table): void {
                $table->foreign('preferred_speech_config_id', 'fk_principal_preferences_preferred_speech_config_id')
                    ->references('id')->on('speech_provider_configurations')
                    ->nullOnDelete();
            });
        }
    }

    private function removeSpeechAgentToolOverrides(\Illuminate\Database\Schema\Builder $schema): void
    {
        if (!$schema->hasTable('agent_tool_overrides')) {
            return;
        }

        $coreShippedSttClasses = [
            \Spora\Speech\OpenAiCompatibleTranscriber::class,
        ];

        $deleted = Capsule::table('agent_tool_overrides')
            ->whereIn('tool_class', $coreShippedSttClasses)
            ->delete();

        // Log the count so operators can see exactly what got swept
        // when they upgrade. Useful for verifying the migration
        // without a separate query.
        if ($deleted > 0) {
            error_log("[migration 0084] removed {$deleted} agent_tool_overrides row(s) for core-shipped STT classes");
        }
    }
};
