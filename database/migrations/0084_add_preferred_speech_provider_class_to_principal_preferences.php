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

        // Restore the legacy class-string column so the
        // pre-0084 `preferred_speech_provider_class` field is back
        // for any downgraded install. Populated from the FK target's
        // `provider_class` where available (this is the only
        // recoverable mapping; user-entered overrides that pointed at
        // a since-deleted row fall through to NULL).
        if (!$schema->hasColumn('principal_preferences', 'preferred_speech_provider_class')) {
            $schema->table('principal_preferences', static function (Blueprint $table): void {
                $table->string('preferred_speech_provider_class')->nullable()->after('preferred_llm_config_id');
            });
        }
        if ($schema->hasColumn('principal_preferences', 'preferred_speech_provider_class')) {
            Capsule::table('principal_preferences')
                ->whereNotNull('preferred_speech_config_id')
                ->orderBy('id')
                ->chunkById(100, function (\Illuminate\Support\Collection $rows): void {
                    foreach ($rows as $row) {
                        $providerClass = Capsule::table('speech_provider_configurations')
                            ->where('id', $row->preferred_speech_config_id)
                            ->value('provider_class');
                        if (is_string($providerClass) && $providerClass !== '') {
                            Capsule::table('principal_preferences')
                                ->where('id', $row->id)
                                ->update(['preferred_speech_provider_class' => $providerClass]);
                        }
                    }
                });
        }

        // Restore the agent_tool_overrides rows from the backup table
        // captured during `up()`. The backup lives next to the regular
        // table so a `down()` finds it without env-specific config.
        if ($schema->hasTable('agent_tool_overrides_0084_backup')) {
            $existing = Capsule::table('agent_tool_overrides')
                ->select(['agent_id', 'tool_class'])
                ->get();
            $existingKeys = [];
            foreach ($existing as $row) {
                $existingKeys[(int) $row->agent_id . ':' . (string) $row->tool_class] = true;
            }
            $restored = 0;
            foreach (Capsule::table('agent_tool_overrides_0084_backup')->orderBy('id')->get() as $backup) {
                $key = (int) $backup->agent_id . ':' . (string) $backup->tool_class;
                if (isset($existingKeys[$key])) {
                    continue;
                }
                Capsule::table('agent_tool_overrides')->insert([
                    'agent_id'   => $backup->agent_id,
                    'tool_class' => $backup->tool_class,
                    'settings'   => $backup->settings,
                    'created_at' => $backup->created_at,
                    'updated_at' => $backup->updated_at,
                ]);
                $existingKeys[$key] = true;
                $restored++;
            }
            if ($restored > 0) {
                error_log("[migration 0084 down] restored {$restored} agent_tool_overrides row(s) from agent_tool_overrides_0084_backup");
            }
            // Leave the backup table in place — operators can drop it
            // in a future migration once they're confident the down
            // worked. Removing it automatically would lock out a
            // second `up()` / `down()` cycle.
        }
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

        // Snapshot every row about to be deleted into
        // `agent_tool_overrides_0084_backup` so a `down()` can restore
        // them. We capture the full row (settings included) — settings
        // are encrypted bytes copied verbatim, so the backup mirrors
        // the live table's encryption-at-rest posture and survives a
        // round-trip without re-keying.
        if (!$schema->hasTable('agent_tool_overrides_0084_backup')) {
            $schema->create('agent_tool_overrides_0084_backup', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('agent_id');
                $table->string('tool_class');
                $table->text('settings')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['agent_id', 'tool_class'], 'idx_agent_tool_overrides_0084_backup_lookup');
            });
        }

        $backedUp = 0;
        Capsule::table('agent_tool_overrides')
            ->whereIn('tool_class', $coreShippedSttClasses)
            ->orderBy('id')
            ->chunkById(100, function (\Illuminate\Support\Collection $rows) use (&$backedUp): void {
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = [
                        'agent_id'   => $row->agent_id,
                        'tool_class' => $row->tool_class,
                        'settings'   => $row->settings,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                    $backedUp++;
                }
                if ($batch !== []) {
                    Capsule::table('agent_tool_overrides_0084_backup')->insert($batch);
                }
            });

        $deleted = Capsule::table('agent_tool_overrides')
            ->whereIn('tool_class', $coreShippedSttClasses)
            ->delete();

        // Log the count so operators can see exactly what got swept
        // when they upgrade. Useful for verifying the migration
        // without a separate query.
        if ($deleted > 0) {
            error_log("[migration 0084] removed {$deleted} agent_tool_overrides row(s) for core-shipped STT classes ({$backedUp} backed up)");
        }
    }
};
