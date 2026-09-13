<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add `is_default` to the two speech-config tables so the operator
 * can mark one global row + one per-principal row per registered
 * STT class as the default.
 *
 * Invariant: at most one `is_default = true` per (scope, principal_id,
 * tool_class) group is service-enforced (see
 * {@see \Spora\Services\SpeechProviderConfigService::setDefaultConfig()}).
 * Mirrors the LLM-side pattern in migration 0011 — `is_default` on
 * `llm_driver_configurations` was the original model; the cascade in
 * {@see \Spora\Speech\SpeechToTextRegistry::configuredProvider()} consumes
 * the column on tier 4 (global default) plus tier 2 / 3 (per-principal
 * preferred class lookup, which itself is stored on
 * `principal_preferences.preferred_speech_provider_class` — see
 * migration 0084).
 *
 * Indexes:
 *   - `idx_tool_configurations_default` on `is_default` — speeds the
 *     tier-4 lookup (`WHERE tool_class IN (…) AND is_default = true`).
 *   - `idx_tool_user_settings_default` on `(principal_id, is_default)`
 *     — speeds the tier-2 / tier-3 lookup of "does this principal have
 *     a default config?".
 *
 * Idempotency:
 *   Every step gates on `hasColumn()` / `indexExists()` from
 *   {@see \Spora\Core\Database\MigrationHelpers} so re-running this
 *   migration after a partial failure is a no-op. No backfill — the
 *   default `false` for `is_default` keeps existing rows out of the
 *   default slot, so no operator-visible state changes at upgrade time.
 */
return new class extends Migration {
    use Spora\Core\Database\MigrationHelpers;

    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('tool_configurations')
            && !$schema->hasColumn('tool_configurations', 'is_default')
        ) {
            $schema->table('tool_configurations', static function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->after('tool_name');
            });
        }

        if ($schema->hasTable('tool_configurations')
            && !$this->indexExists('tool_configurations', 'idx_tool_configurations_default')
        ) {
            $schema->table('tool_configurations', static function (Blueprint $table): void {
                $table->index('is_default', 'idx_tool_configurations_default');
            });
        }

        if ($schema->hasTable('tool_user_settings')
            && !$schema->hasColumn('tool_user_settings', 'is_default')
        ) {
            $schema->table('tool_user_settings', static function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->after('tool_class');
            });
        }

        if ($schema->hasTable('tool_user_settings')
            && !$this->indexExists('tool_user_settings', 'idx_tool_user_settings_default')
        ) {
            $schema->table('tool_user_settings', static function (Blueprint $table): void {
                $table->index(['principal_id', 'is_default'], 'idx_tool_user_settings_default');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('tool_user_settings')
            && $this->indexExists('tool_user_settings', 'idx_tool_user_settings_default')
        ) {
            $schema->table('tool_user_settings', static function (Blueprint $table): void {
                $table->dropIndex('idx_tool_user_settings_default');
            });
        }

        if ($schema->hasTable('tool_user_settings')
            && $schema->hasColumn('tool_user_settings', 'is_default')
        ) {
            $schema->table('tool_user_settings', static function (Blueprint $table): void {
                $table->dropColumn('is_default');
            });
        }

        if ($schema->hasTable('tool_configurations')
            && $this->indexExists('tool_configurations', 'idx_tool_configurations_default')
        ) {
            $schema->table('tool_configurations', static function (Blueprint $table): void {
                $table->dropIndex('idx_tool_configurations_default');
            });
        }

        if ($schema->hasTable('tool_configurations')
            && $schema->hasColumn('tool_configurations', 'is_default')
        ) {
            $schema->table('tool_configurations', static function (Blueprint $table): void {
                $table->dropColumn('is_default');
            });
        }
    }
};
