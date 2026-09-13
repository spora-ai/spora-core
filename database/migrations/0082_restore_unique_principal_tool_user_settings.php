<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Restore the unique constraint on `tool_user_settings.(principal_id, tool_class)`.
 *
 * History:
 *
 *   - 0024_create_tool_user_settings_table.php created the constraint as
 *     `unique(['user_id', 'tool_class'], 'uq_tool_user_settings')`.
 *   - 0067_introduce_principals_and_groups.php dropped the `user_id` column
 *     via `rebuildSqliteTableWithoutUserId()` — a snapshot → DROP → CREATE
 *     that walks `PRAGMA table_info` and `PRAGMA foreign_key_list` but NOT
 *     `PRAGMA index_list`, so every user-created index on the source table
 *     was silently lost. The unique index was on the way out.
 *     The new key would have been `(principal_id, tool_class)`; the
 *     `user_id → principal_id` swap never re-added it.
 *
 * Without the constraint, `ToolConfigService::putPrincipalSettings()` does
 * a SELECT-then-INSERT, which is vulnerable to TOCTOU races: two requests
 * updating the same `(principal_id, tool_class)` at the same instant both
 * see "no row" and both INSERT, producing duplicates. An operator hit this
 * with `id=2` for what should have been an update of `id=1`.
 *
 * This migration restores the constraint on the new key. The new name
 * (`uq_tool_user_settings_principal_tool`) intentionally differs from the
 * pre-0067 name (`uq_tool_user_settings`) because the pre-0067 constraint
 * was on `(user_id, tool_class)` and may still exist on databases that
 * pre-date 0067; reusing the old name would collide.
 *
 * Forward-only rationale:
 *
 *   `down()` is a no-op. Dropping the constraint re-enables the
 *   duplicate-row foot-gun that this migration exists to close.
 *
 * Idempotency:
 *
 *   Every step gates on `indexExists()` (driver-aware, via
 *   {@see \Spora\Core\Database\MigrationHelpers}) or a duplicate-row COUNT,
 *   so re-running this migration on a clean DB or after a partial run is a
 *   no-op.
 *
 * Dedup strategy:
 *
 *   For each `(principal_id, tool_class)` group with > 1 row, keep the row
 *   with the highest `(updated_at, id)` and delete the rest. NULL
 *   `updated_at` is treated as the epoch so rows written before the column
 *   carried a guaranteed NOT NULL default sort last.
 *
 *   The kept row's settings blob is the canonical state — duplicates from
 *   the SELECT-then-INSERT race mean the loser's settings are stale by
 *   definition, so the settings are NOT merged. (A merged settings map
 *   could resurrect stale keys that an explicit later delete had cleared.)
 *
 * Engine handling:
 *
 *   The dedup DELETE uses different SQL per driver. SQLite doesn't accept
 *   `DELETE t1 FROM ... t2 INNER JOIN` (the multi-table DELETE form is
 *   MySQL/MariaDB only); the SQLite path wraps `WHERE id IN (SELECT …)`
 *   in an extra `SELECT * FROM (...)` so the inner aggregate query is a
 *   real table. MySQL/MariaDB use the native multi-table DELETE form.
 */
return new class extends Migration {
    use Spora\Core\Database\MigrationHelpers;

    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();
        $table = 'tool_user_settings';
        $indexName = 'uq_tool_user_settings_principal_tool';

        if (!$schema->hasTable($table)) {
            return;
        }

        if ($this->hasDuplicatePairs($table)) {
            $this->deduplicate($driver, $table);
        }

        if (!$this->indexExists($table, $indexName)) {
            $schema->table($table, static function (Blueprint $t) use ($indexName): void {
                $t->unique(['principal_id', 'tool_class'], $indexName);
            });
        }
    }

    public function down(): void
    {
        // Forward-only. See class docblock — dropping the constraint
        // re-enables the duplicate-row foot-gun.
    }

    private function hasDuplicatePairs(string $table): bool
    {
        $row = Capsule::selectOne(
            "SELECT COUNT(*) AS c FROM (
                SELECT principal_id, tool_class
                FROM {$table}
                GROUP BY principal_id, tool_class
                HAVING COUNT(*) > 1
            )",
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