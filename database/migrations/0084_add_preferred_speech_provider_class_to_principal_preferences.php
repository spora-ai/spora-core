<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add `preferred_speech_provider_class` to `principal_preferences`.
 *
 * The column stores the FQCN (a class string) rather than a foreign
 * key to either `tool_configurations` or `tool_user_settings` because
 * speech configs span both tables — global configs land in
 * `tool_configurations` (admin scope), per-user / per-group configs
 * land in `tool_user_settings` (keyed by `principal_id`). A single FK
 * can't reach both; a class string is the natural key and lets the
 * cascade in {@see \Spora\Speech\SpeechToTextRegistry::configuredProvider()}
 * walk tiers 2 / 3 ("look up the row for the preferred class at the
 * current scope").
 *
 * Mirrors `principal_preferences.preferred_llm_config_id` (a numeric
 * FK to `llm_driver_configurations`) but lighter — no FK, since the
 * class is a string and the registry validates it's a registered
 * STT class at read time (an unregistered class is treated as unset,
 * see `SpeechToTextRegistry::resolvePreferredClass()`).
 *
 * Width: 200 chars matches the `tool_class` column width on
 * `tool_configurations` / `tool_user_settings` (also VARCHAR(200)) so
 * a class FQCN fits comfortably without a migration-driven width bump
 * if the operator updates the class namespace.
 *
 * Idempotency:
 *   Gated on `hasColumn()` via the standard migration helpers, so a
 *   re-run on a clean DB or after a partial failure is a no-op. No
 *   backfill — the default `NULL` means "no preference set", which
 *   preserves every existing row's behaviour (tier 5 fallback).
 *
 * Pre-existing schema context:
 *
 *   `principal_preferences` was created as `user_preferences` in
 *   migration 0048 with `UNIQUE(user_id)`. Migration 0067 renamed it
 *   to `principal_preferences`, replaced `user_id` with `principal_id`
 *   (FK to `principals`), and re-added the unique constraint on
 *   `principal_id` (one row per principal). The new column is a
 *   nullable VARCHAR with no UNIQUE — multiple rows could carry the
 *   same preferred class, but only one row exists per principal so the
 *   column is effectively unique-by-construction.
 *
 * Migration ordering:
 *
 *   This migration (0084) MUST run AFTER 0067 (the principals rename)
 *   and AFTER 0082 (the unique-constraint restore on
 *   `tool_user_settings`) because the cascade consumes
 *   `tool_user_settings.is_default` from migration 0083 to validate
 *   that the preferred class actually has a row at the resolved scope.
 */
return new class extends Migration {
    use Spora\Core\Database\MigrationHelpers;

    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('principal_preferences')) {
            return;
        }

        if (!$schema->hasColumn('principal_preferences', 'preferred_speech_provider_class')) {
            $schema->table('principal_preferences', static function (Blueprint $table): void {
                $table->string('preferred_speech_provider_class', 200)->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('principal_preferences')) {
            return;
        }

        if ($schema->hasColumn('principal_preferences', 'preferred_speech_provider_class')) {
            $schema->table('principal_preferences', static function (Blueprint $table): void {
                $table->dropColumn('preferred_speech_provider_class');
            });
        }
    }
};
