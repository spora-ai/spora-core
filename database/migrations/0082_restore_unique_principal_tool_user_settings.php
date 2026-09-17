<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Two pieces of the speech-storage seam:
 *
 *   1. Restore the unique constraint on
 *      `tool_user_settings.(principal_id, tool_class)`.
 *
 *   History:
 *
 *     - 0024_create_tool_user_settings_table.php created the constraint
 *       as `unique(['user_id', 'tool_class'], 'uq_tool_user_settings')`.
 *     - 0067_introduce_principals_and_groups.php dropped the `user_id`
 *       column via `rebuildSqliteTableWithoutUserId()` — a snapshot →
 *       DROP → CREATE that walks `PRAGMA table_info` and
 *       `PRAGMA foreign_key_list` but NOT `PRAGMA index_list`, so every
 *       user-created index on the source table was silently lost. The
 *       unique index was on the way out. The new key would have been
 *       `(principal_id, tool_class)`; the `user_id → principal_id`
 *       swap never re-added it.
 *
 *   Without the constraint, `ToolConfigService::putPrincipalSettings()`
 *   does a SELECT-then-INSERT, which is vulnerable to TOCTOU races:
 *   two requests updating the same `(principal_id, tool_class)` at the
 *   same instant both see "no row" and both INSERT, producing
 *   duplicates. An operator hit this with `id=2` for what should have
 *   been an update of `id=1`.
 *
 *   This migration restores the constraint on the new key. The new
 *   name (`uq_tool_user_settings_principal_tool`) intentionally
 *   differs from the pre-0067 name (`uq_tool_user_settings`) because
 *   the pre-0067 constraint was on `(user_id, tool_class)` and may
 *   still exist on databases that pre-date 0067; reusing the old name
 *   would collide.
 *
 *   Forward-only rationale:
 *
 *     `down()` is a no-op. Dropping the constraint re-enables the
 *     duplicate-row foot-gun that this migration exists to close.
 *
 *   Idempotency:
 *
 *     Every step gates on `indexExists()` (driver-aware, via
 *     {@see \Spora\Core\Database\MigrationHelpers}) or a duplicate-row
 *     COUNT, so re-running this migration on a clean DB or after a
 *     partial run is a no-op.
 *
 *   Dedup strategy:
 *
 *     For each `(principal_id, tool_class)` group with > 1 row, keep
 *     the row with the highest `(updated_at, id)` and delete the rest.
 *     NULL `updated_at` is treated as the epoch so rows written before
 *     the column carried a guaranteed NOT NULL default sort last.
 *
 *     The kept row's settings blob is the canonical state — duplicates
 *     from the SELECT-then-INSERT race mean the loser's settings are
 *     stale by definition, so the settings are NOT merged. (A merged
 *     settings map could resurrect stale keys that an explicit later
 *     delete had cleared.)
 *
 *   Engine handling:
 *
 *     The dedup DELETE uses different SQL per driver. SQLite doesn't
 *     accept `DELETE t1 FROM ... t2 INNER JOIN` (the multi-table
 *     DELETE form is MySQL/MariaDB only); the SQLite path wraps
 *     `WHERE id IN (SELECT …)` in an extra `SELECT * FROM (...)` so
 *     the inner aggregate query is a real table. MySQL/MariaDB use the
 *     native multi-table DELETE form.
 *
 *   2. Create `speech_provider_configurations` and the FK on
 *      `agents.speech_driver_config_id`.
 *
 *   The new speech-to-text storage table — the speech mirror of
 *   `llm_driver_configurations`. Speaks the same wire shape the SPA
 *   already consumes on the LLM side (id, display_name, provider_class,
 *   settings, is_global, is_default, principal_id) so the new
 *   `SpeechProviderConfigController` can return identical resource DTOs
 *   and the operator-facing table in the frontend doesn't need to learn
 *   a new key.
 *
 *   Until migration 0083 the speech configs span two storage tables:
 *
 *     - `tool_configurations` (admin / global scope, one row per
 *       registered STT class via `tool_class` unique key)
 *     - `tool_user_settings` (per-user / per-group scope, keyed by
 *       `principal_id` after migration 0067's principals cutover)
 *
 *   A single FK-keyed `speech_provider_configurations` table replaces
 *   both for the speech slice only — the `tool_configurations` and
 *   `tool_user_settings` rows continue to exist because other tools
 *   (email, calendar, weather, serper, tavily, …) still write to them.
 *
 *   Width decisions, mirrored from `llm_driver_configurations`:
 *
 *     - `display_name` 100 chars (operator-friendly label, matches LLM).
 *     - `provider_class` 200 chars (covers current and foreseeable
 *       FQCNs including the plugin namespace).
 *     - `settings` TEXT — encrypted blob via the same key the rest of
 *       the app uses (`SecurityManager`); see {@see SettingsCrypto} for
 *       the encryption wrapper the persistence layer applies.
 *
 *   Indexes:
 *
 *     - `(provider_class)` — "all configs for one provider class".
 *     - `(principal_id)` — "all configs for one principal".
 *     - `(is_global, is_default)` — "the global default" lookup, the
 *       tier-4 read in
 *       {@see \Spora\Speech\SpeechToTextRegistry::resolveEffectiveClassWithSource()}.
 *
 *   FKs:
 *
 *     - `principal_id` → `principals(id)` ON DELETE CASCADE. A principal
 *       cascades its own-configs, matching the LLM side; the FK is
 *       nullable so the `is_global = true` row holds `principal_id = null`
 *       per the same XOR invariant
 *       {@see LLMDriverConfiguration::validateGlobalXor()} enforces on
 *       the LLM model.
 *     - `agents.speech_driver_config_id` → `speech_provider_configurations(id)`
 *       ON DELETE SET NULL. The column was added by migration 0081
 *       (the table didn't exist yet); 0082 closes the loop by adding
 *       the FK. Mirrors `agents.llm_driver_config_id` introduced
 *       alongside `llm_driver_configurations` for the LLM side.
 *
 *   Idempotency / re-run safety: every step (`hasTable`, `hasColumn`,
 *   `foreignKeyExists`) is gated; the migration file is safe to
 *   re-run against a partial state.
 */
return new class extends Migration {
    use Spora\Core\Database\MigrationHelpers;

    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();
        $table = 'tool_user_settings';
        $indexName = 'uq_tool_user_settings_principal_tool';

        if ($schema->hasTable($table)) {
            if ($this->hasDuplicatePairs($table)) {
                $this->deduplicate($driver, $table);
            }

            if (!$this->indexExists($table, $indexName)) {
                $schema->table($table, static function (Blueprint $t) use ($indexName): void {
                    $t->unique(['principal_id', 'tool_class'], $indexName);
                });
            }
        }

        $this->createSpeechProviderConfigurationsTable($schema);
        $this->addSpeechDriverConfigForeignKey($schema);
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        // Forward-only on the unique constraint — see class docblock:
        // dropping it re-enables the duplicate-row foot-gun.

        // Drop the agents FK before dropping the speech table so the
        // agents.speech_driver_config_id column is left dangling-free
        // for migration 0081's `down()` to remove the column itself.
        // SQLite doesn't support dropping FKs by name — drop the
        // column instead (the FK goes with it), matching the
        // media_assets.principal_id precedent in migration 0075.
        if ($driver === 'sqlite') {
            if ($schema->hasTable('agents')
                && $schema->hasColumn('agents', 'speech_driver_config_id')
            ) {
                if ($this->indexExists('agents', 'idx_agents_speech_driver_config_id')) {
                    $schema->table('agents', static function (Blueprint $t): void {
                        $t->dropIndex('idx_agents_speech_driver_config_id');
                    });
                }
                $schema->table('agents', static function (Blueprint $t): void {
                    $t->dropColumn('speech_driver_config_id');
                });
            }
        } else {
            if ($schema->hasTable('agents')
                && $this->foreignKeyExists('agents', 'fk_agents_speech_driver_config_id')
            ) {
                $schema->table('agents', static function (Blueprint $t): void {
                    $t->dropForeign('fk_agents_speech_driver_config_id');
                });
            }
            if ($schema->hasTable('agents')
                && $schema->hasColumn('agents', 'speech_driver_config_id')
            ) {
                $schema->table('agents', static function (Blueprint $t): void {
                    $t->dropColumn('speech_driver_config_id');
                });
            }
        }

        if ($schema->hasTable('speech_provider_configurations')) {
            if ($driver !== 'sqlite'
                && $this->foreignKeyExists('speech_provider_configurations', 'fk_speech_provider_configurations_principal_id')
            ) {
                $schema->table('speech_provider_configurations', static function (Blueprint $t): void {
                    $t->dropForeign('fk_speech_provider_configurations_principal_id');
                });
            }
            $schema->dropIfExists('speech_provider_configurations');
        }
    }

    private function createSpeechProviderConfigurationsTable(\Illuminate\Database\Schema\Builder $schema): void
    {
        if ($schema->hasTable('speech_provider_configurations')) {
            return;
        }

        $schema->create('speech_provider_configurations', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('principal_id')->nullable();
            $table->string('provider_class', 200);
            $table->string('display_name', 100);
            $table->text('settings')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_global')->default(false);
            $table->timestamps();

            $table->index('provider_class', 'idx_speech_provider_configurations_provider_class');
            $table->index('principal_id', 'idx_speech_provider_configurations_principal_id');
            $table->index(['is_global', 'is_default'], 'idx_speech_provider_configurations_global_default');
        });

        if (!$this->foreignKeyExists('speech_provider_configurations', 'fk_speech_provider_configurations_principal_id')) {
            $schema->table('speech_provider_configurations', static function (Blueprint $table): void {
                $table->foreign('principal_id', 'fk_speech_provider_configurations_principal_id')
                    ->references('id')->on('principals')
                    ->cascadeOnDelete();
            });
        }
    }

    private function addSpeechDriverConfigForeignKey(\Illuminate\Database\Schema\Builder $schema): void
    {
        if (!$schema->hasTable('agents')
            || !$schema->hasColumn('agents', 'speech_driver_config_id')
        ) {
            // 0081 is expected to land the column first; if a partial
            // apply skipped 0081 the FK simply waits for the next run.
            return;
        }

        if (!$this->foreignKeyExists('agents', 'fk_agents_speech_driver_config_id')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->foreign('speech_driver_config_id', 'fk_agents_speech_driver_config_id')
                    ->references('id')->on('speech_provider_configurations')
                    ->nullOnDelete();
            });
        }
    }

    private function hasDuplicatePairs(string $table): bool
    {
        // MariaDB requires every derived table to have an alias (MySQL
        // tolerates the bare form, MariaDB rejects it with SQLSTATE
        // 1064 near the closing paren). SQLite accepts both.
        $row = Capsule::selectOne(
            "SELECT COUNT(*) AS c FROM (
                SELECT principal_id, tool_class
                FROM {$table}
                GROUP BY principal_id, tool_class
                HAVING COUNT(*) > 1
            ) AS duplicates",
        );
        return ((int) ($row->c ?? 0)) > 0;
    }

    private function deduplicate(string $driver, string $table): void
    {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement(
                "DELETE t1 FROM {$table} t1 "
                . "INNER JOIN {$table} t2 "
                . "  ON t1.principal_id = t2.principal_id "
                . "  AND t1.tool_class = t2.tool_class "
                . "WHERE COALESCE(t1.updated_at, '1970-01-01 00:00:00') < COALESCE(t2.updated_at, '1970-01-01 00:00:00') "
                . "   OR ("
                . "     COALESCE(t1.updated_at, '1970-01-01 00:00:00') = COALESCE(t2.updated_at, '1970-01-01 00:00:00') "
                . "     AND t1.id < t2.id"
                . "   )",
            );
            return;
        }

        // SQLite — `WHERE id IN (SELECT …)` rejects references to the
        // outer table, so we wrap the ROW_NUMBER() aggregate in a derived
        // table that SQLite materialises before the outer DELETE runs.
        Capsule::statement(
            "DELETE FROM {$table} WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY principal_id, tool_class
                        ORDER BY COALESCE(updated_at, '1970-01-01 00:00:00') DESC, id DESC
                    ) AS rn
                    FROM {$table}
                ) WHERE rn > 1
            )",
        );
    }
};