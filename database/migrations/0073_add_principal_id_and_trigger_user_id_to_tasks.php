<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Split `tasks.user_id` into two purpose-specific columns so groups can
 * share agents and see each other's runs.
 *
 *   - `tasks.principal_id` — the principal that owns the task. Always set;
 *     mirrors `agents.principal_id` for visibility + transfer-propagation
 *     semantics. Updated on agent transfer (bulk UPDATE on the affected
 *     `tasks` rows) so the new owner inherits every inherited run.
 *   - `tasks.trigger_user_id` — the user who clicked "Send". Nullable so
 *     future system-generated tasks (cron, webhooks) can land without a
 *     human trigger. Carries no access-control weight — it is purely an
 *     audit / "filter by who started" field. NOT updated on agent
 *     transfer (a historical "user X started this chat" attribution
 *     outlives ownership changes).
 *
 * The pre-existing `tasks.user_id` column conflated the two meanings.
 * `principal_id` replaces it for access-control (visibility, per-task
 * action gating, transfer propagation), and `trigger_user_id` carries
 * the immutable attribution forward.
 *
 * Forward-only: `down()` is a no-op. Operators who need to roll back
 * should restore from a backup taken before the upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        // 1) Add principal_id (nullable initially so the backfill can
        //    land). Idempotent: a previous partial run may have already
        //    added the column.
        if (!$schema->hasColumn('tasks', 'principal_id')) {
            $schema->table('tasks', static function (Blueprint $t): void {
                $t->unsignedBigInteger('principal_id')->nullable()->after('agent_id');
                $t->index('principal_id', 'idx_tasks_principal_id');
            });
        } elseif (!$this->indexExists('tasks', 'idx_tasks_principal_id')) {
            $schema->table('tasks', static function (Blueprint $t): void {
                $t->index('principal_id', 'idx_tasks_principal_id');
            });
        }

        // 2) Add trigger_user_id (nullable by design — system-generated
        //    tasks with no human trigger are allowed). Same idempotency
        //    guards as principal_id.
        if (!$schema->hasColumn('tasks', 'trigger_user_id')) {
            $schema->table('tasks', static function (Blueprint $t): void {
                $t->unsignedBigInteger('trigger_user_id')->nullable()->after('user_id');
                $t->index('trigger_user_id', 'idx_tasks_trigger_user_id');
            });
        } elseif (!$this->indexExists('tasks', 'idx_tasks_trigger_user_id')) {
            $schema->table('tasks', static function (Blueprint $t): void {
                $t->index('trigger_user_id', 'idx_tasks_trigger_user_id');
            });
        }

        // 3) Backfill principal_id from the AGENT's principal_id, not
        //    the clicker's user-principal. A task on a user-owned agent
        //    inherits the agent's user-principal; a task on a group-owned
        //    agent inherits the agent's group-principal. The
        //    earlier "user-principal of trigger_user_id" backfill left
        //    group-owned agents' tasks attributed to whoever clicked
        //    Send, breaking the group-shared visibility contract
        //    (only the original clicker could see their own task on a
        //    group agent). The corrected backfill aligns every task with
        //    its agent's owner principal so the visibility scoping
        //    (`whereIn('principal_id', $visiblePrincipalIds)`) works
        //    the same way for tasks as it does for the agent itself.
        //
        //    The post-backfill orphan check below (no principal_id left
        //    null) still holds because every task has a non-null
        //    agent.principal_id post-migration-0067.
        Capsule::table('tasks')
            ->whereNull('principal_id')
            ->update([
                'principal_id' => Capsule::raw('(SELECT principal_id FROM agents WHERE agents.id = tasks.agent_id)'),
            ]);

        $missingPrincipal = (int) Capsule::table('tasks')->whereNull('principal_id')->count();
        if ($missingPrincipal > 0) {
            throw new \RuntimeException(
                "principal_id backfill left {$missingPrincipal} task row(s) orphaned — "
                . 'every tasks.user_id must resolve to a principals.user_id before this '
                . 'migration can proceed.'
            );
        }

        // 4) Backfill trigger_user_id = user_id (direct copy; trigger_user_id
        //    IS a user_id, no principal lookup needed). Idempotent — re-runs
        //    are no-ops.
        Capsule::table('tasks')
            ->whereNotNull('user_id')
            ->whereNull('trigger_user_id')
            ->update(['trigger_user_id' => Capsule::raw('user_id')]);

        // 5) Promote principal_id to NOT NULL — every row has a principal
        //    post-backfill. The CASCADE chain (users → principals → tasks)
        //    replaces the deleted users → tasks chain.
        $schema->table('tasks', static function (Blueprint $t): void {
            $t->unsignedBigInteger('principal_id')->nullable(false)->change();
        });

        if (!$this->foreignKeyExists('tasks', 'fk_tasks_principal_id')) {
            $schema->table('tasks', static function (Blueprint $t): void {
                $t->foreign('principal_id', 'fk_tasks_principal_id')
                    ->references('id')->on('principals')
                    ->cascadeOnDelete();
            });
        }

        // 6) trigger_user_id FK with SET NULL on user delete: the task
        //    survives the user delete (so the chat history outlives the
        //    user), but the attribution column is nulled.
        if (!$this->foreignKeyExists('tasks', 'fk_tasks_trigger_user_id')) {
            $schema->table('tasks', static function (Blueprint $t): void {
                $t->foreign('trigger_user_id', 'fk_tasks_trigger_user_id')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }

        // 7) Drop user_id + its FK + idx_tasks_user_id. Driver-aware:
        //    MySQL/MariaDB use direct ALTER TABLE; SQLite requires a
        //    table rebuild because ALTER TABLE … DROP COLUMN refuses to drop
        //    a column referenced by a FK.
        if ($schema->hasColumn('tasks', 'user_id')) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $fkName = $this->findForeignKeyOn('tasks', 'user_id');
                if ($fkName !== null) {
                    Capsule::statement("ALTER TABLE tasks DROP FOREIGN KEY {$fkName}");
                }
                $idxName = $this->findIndexOn('tasks', 'user_id');
                if ($idxName !== null) {
                    Capsule::statement("ALTER TABLE tasks DROP INDEX {$idxName}");
                }
                $schema->table('tasks', static function (Blueprint $t): void {
                    $t->dropColumn('user_id');
                });
            } else {
                $this->rebuildSqliteTableWithoutUserId();
                // SQLite's rebuildSqliteTableWithoutUserId recreates the
                // table from PRAGMA table_info + PRAGMA foreign_key_list
                // only — non-FK indexes are silently dropped. Restore the
                // reaper-critical composite index added by migration 0070
                // so the production reaper scan doesn't degrade to a full
                // table scan after this column-swap.
                Capsule::statement(
                    'CREATE INDEX IF NOT EXISTS tasks_status_lease_expires_at_index '
                    . 'ON tasks (status, lease_expires_at)',
                );
            }
        }
    }

    public function down(): void
    {
        // Forward-only — see class docblock. The split of user_id into
        // principal_id + trigger_user_id is not losslessly reversible
        // (we cannot tell whether the original user_id was meant to
        // represent ownership, attribution, or both).
    }

    /** Driver-aware FK existence check. Mirrors `0067_introduce_principals_and_groups`. */
    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
                . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . "AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
                [$table, $constraintName],
            );
            return $row !== null;
        }

        $column = substr($constraintName, strlen("fk_{$table}_"));
        $fks = Capsule::select("PRAGMA foreign_key_list('{$table}')");
        foreach ($fks as $fk) {
            if ($fk->from === $column) {
                return true;
            }
        }
        return false;
    }

    /** Driver-aware index existence check. Mirrors `0067`. */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . 'AND INDEX_NAME = ? LIMIT 1',
                [$table, $indexName],
            );
            return $row !== null;
        }

        $rows = Capsule::select("PRAGMA index_list('{$table}')");
        foreach ($rows as $row) {
            if ($row->name === $indexName) {
                return true;
            }
        }
        return false;
    }

    /** Driver-aware lookup for the FK that references $column on $table. Mirrors `0067`. */
    private function findForeignKeyOn(string $table, string $column): ?string
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT kcu.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE kcu '
                . 'INNER JOIN information_schema.TABLE_CONSTRAINTS tc '
                . 'ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA '
                . 'AND tc.TABLE_NAME = kcu.TABLE_NAME '
                . 'AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME '
                . 'WHERE kcu.TABLE_SCHEMA = DATABASE() '
                . 'AND kcu.TABLE_NAME = ? '
                . 'AND kcu.COLUMN_NAME = ? '
                . "AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
                [$table, $column],
            );
            return $row?->CONSTRAINT_NAME;
        }
        return null;
    }

    /** Driver-aware lookup for the index whose leftmost column is $column. Mirrors `0067`. */
    private function findIndexOn(string $table, string $column): ?string
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = ? '
                . 'AND COLUMN_NAME = ? '
                . 'AND SEQ_IN_INDEX = 1 '
                . "AND INDEX_NAME <> 'PRIMARY' LIMIT 1",
                [$table, $column],
            );
            return $row?->INDEX_NAME;
        }
        $rows = Capsule::select("PRAGMA index_list('{$table}')");
        foreach ($rows as $row) {
            if ($row->origin !== 'c') {
                continue;
            }
            $cols = Capsule::select("PRAGMA index_info('{$row->name}')");
            if ($cols !== [] && $cols[0]->name === $column) {
                return $row->name;
            }
        }
        return null;
    }

    /**
     * Rebuild the SQLite `tasks` table without its `user_id` column.
     * Mirrors `0067_introduce_principals_and_groups::rebuildSqliteTableWithoutUserId`
     * — same `PRAGMA foreign_keys = OFF` requirement outside a transaction
     * so the DROP doesn't cascade-delete `task_history`, `tool_calls`,
     * and every other `tasks.id`-FK'd row.
     *
     * Public so the migration test can drive a regression that asserts
     * the dependent rows survive the rebuild.
     */
    public function rebuildSqliteTableWithoutUserId(): void
    {
        $effective = 'tasks';
        $rows = Capsule::table($effective)->get()->all();

        $originalColumns = Capsule::select("PRAGMA table_info('{$effective}')");
        $columnsToKeep = [];
        $columnDefs = [];
        foreach ($originalColumns as $col) {
            if ($col->name === 'user_id') {
                continue;
            }
            $columnsToKeep[] = $col->name;
            $isPk = ((int) $col->pk) > 0;
            $dflt = '';
            if ($col->dflt_value !== null && strtoupper((string) $col->dflt_value) !== 'NULL') {
                $dflt = ' DEFAULT ' . $col->dflt_value;
            }
            $columnDefs[] = sprintf(
                '"%s" %s%s%s%s',
                $col->name,
                $col->type,
                ($col->notnull && !$isPk ? ' NOT NULL' : ''),
                $dflt,
                ($isPk ? ' PRIMARY KEY' : ''),
            );
        }

        $originalFks = Capsule::select("PRAGMA foreign_key_list('{$effective}')");
        $fkDefs = [];
        foreach ($originalFks as $fk) {
            if ($fk->from === 'user_id') {
                continue;
            }
            $key = $fk->id;
            if (!isset($fkDefs[$key])) {
                $fkDefs[$key] = [
                    'table' => $fk->table,
                    'columns' => [],
                    'references' => [],
                ];
            }
            $fkDefs[$key]['columns'][] = $fk->from;
            $fkDefs[$key]['references'][] = $fk->to;
        }

        $sql = "CREATE TABLE {$effective} (\n  ";
        $sql .= implode(",\n  ", $columnDefs);
        foreach ($fkDefs as $def) {
            $cols = implode(', ', array_map(static fn($c) => "\"{$c}\"", $def['columns']));
            $refs = implode(', ', array_map(static fn($r) => "\"{$r}\"", $def['references']));
            $sql .= ",\n  FOREIGN KEY ({$cols}) REFERENCES \"{$def['table']}\" ({$refs})";
        }
        $sql .= "\n)";

        Capsule::statement('PRAGMA foreign_keys = OFF');
        $actual = (int) Capsule::selectOne('PRAGMA foreign_keys')->foreign_keys;
        if ($actual !== 0) {
            throw new \RuntimeException(sprintf(
                'PRAGMA foreign_keys = OFF did not take effect on the %s connection '
                . '(read back %d, expected 0). Aborting the migration rather than '
                . 'risk silent data loss.',
                $effective,
                $actual,
            ));
        }

        Capsule::statement("DROP TABLE {$effective}");
        Capsule::statement($sql);

        if ($rows !== []) {
            $colsList = implode(', ', array_map(static fn($c) => "\"{$c}\"", $columnsToKeep));
            $insertSql = "INSERT INTO {$effective} ({$colsList}) VALUES ";
            $valueRows = [];
            $bindings = [];
            foreach ($rows as $row) {
                $placeholders = [];
                foreach ($columnsToKeep as $c) {
                    $placeholders[] = '?';
                    $bindings[] = $row->{$c} ?? null;
                }
                $valueRows[] = '(' . implode(',', $placeholders) . ')';
            }
            $insertSql .= implode(',', $valueRows);
            Capsule::insert($insertSql, $bindings);
        }

        Capsule::statement('PRAGMA foreign_keys = ON');
        $actual = (int) Capsule::selectOne('PRAGMA foreign_keys')->foreign_keys;
        if ($actual !== 1) {
            throw new \RuntimeException(sprintf(
                'PRAGMA foreign_keys = ON did not take effect on the %s connection '
                . '(read back %d, expected 1). Aborting; re-run after the connection-pool '
                . 'issue is resolved.',
                $effective,
                $actual,
            ));
        }
    }
};