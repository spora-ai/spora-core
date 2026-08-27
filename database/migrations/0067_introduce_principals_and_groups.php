<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Introduce principals, groups, and group_memberships, and re-key every
 * ownership column from user_id to principal_id.
 *
 * What this migration does:
 *
 *   1. Creates three new tables (`groups`, `group_memberships`, `principals`)
 *      that hold group membership and a unified principal pointer.
 *   2. Bulk-inserts one user-principal per existing user (idempotent; safe
 *      against concurrent inserts via a UNIQUE index + ON CONFLICT DO NOTHING).
 *   3. Adds a nullable `principal_id` column to the three settings tables
 *      (`llm_driver_configurations`, `tool_user_settings`, `user_preferences`),
 *      backfills it via a JOIN-style UPDATE, then promotes it to NOT NULL and
 *      adds a FK to `principals(id)`. `user_preferences` is renamed to
 *      `principal_preferences` in the same step.
 *   4. Adds `principal_id` to `agents`, backfills it from `agents.user_id`,
 *      promotes it to NOT NULL, drops the old `agents.user_id` FK + column,
 *      and adds the `agents.principal_id → principals(id)` FK with RESTRICT
 *      (deleting a principal does not cascade-orphan agents; controllers do
 *      a pre-flight and surface a structured 409).
 *
 * Why three phases (DDL outside transaction + DML inside + column swap
 * outside transaction):
 *
 *   Phase 1 (new tables) and Phase 3 (the `user_id → principal_id` column
 *   swap on the settings tables and on `agents`) run **outside** any
 *   transaction. Phase 2 (DML backfill of `user_id` to `principal_id`)
 *   runs inside a single transaction so a partial backfill cannot leave
 *   the database in an inconsistent state.
 *
 *   The reason Phase 3 cannot be in a transaction: SQLite documents that
 *   `PRAGMA foreign_keys = OFF` is a **no-op inside a transaction** (see
 *   https://sqlite.org/pragma.html#pragma_foreign_keys — "This pragma is
 *   a no-op within a transaction; foreign key constraint enforcement may
 *   only be enabled or disabled when there is no pending BEGIN or
 *   SAVEPOINT"). The original implementation of this migration wrapped
 *   the rebuild in the same `Capsule::connection()->transaction(...)`
 *   block as the DML backfill, so the `PRAGMA foreign_keys = OFF` it used
 *   to suppress cascade during `DROP TABLE agents` was a no-op — every
 *   table that held `FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE
 *   CASCADE` (`tasks`, `task_history`, `tool_calls`, `agent_tools`, and the
 *   override / scheduled-run / usage / picture tables) was cascade-deleted
 *   alongside the agents. Phase 3 here explicitly runs without a
 *   transaction so the PRAGMA takes effect and the dependent rows survive.
 *
 * Forward-only rationale:
 *
 *   `down()` is a no-op. Rolling back would destroy operator data: the
 *   `user_preferences → principal_preferences` rename, the FK swap on
 *   `agents`, and the bulk-inserted user-principal rows are not all
 *   reversible without knowing the migration order. Operators who need
 *   to roll back should restore from a backup taken before the upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        // Phase 1: new tables (DDL only; safe on both engines).

        if (!$schema->hasTable('groups')) {
            $schema->create('groups', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 120);
                $table->string('description', 500)->nullable();
                $table->unsignedBigInteger('created_by_user_id');
                $table->timestamps();

                $table->foreign('created_by_user_id', 'fk_groups_created_by_user_id')
                    ->references('id')->on('users')
                    ->onDelete('restrict');
            });
        }

        if (!$schema->hasTable('group_memberships')) {
            $schema->create('group_memberships', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('group_id');
                $table->unsignedBigInteger('user_id');
                $table->enum('role', ['owner', 'admin', 'member'])->default('member');
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['group_id', 'user_id'], 'uq_group_memberships_group_user');
                $table->index(['group_id', 'role'], 'idx_group_memberships_group_role');
                $table->foreign('group_id', 'fk_group_memberships_group_id')
                    ->references('id')->on('groups')->cascadeOnDelete();
                $table->foreign('user_id', 'fk_group_memberships_user_id')
                    ->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (!$schema->hasTable('principals')) {
            $schema->create('principals', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->enum('type', ['user', 'group']);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('group_id')->nullable();
                $table->timestamps();

                $table->unique('user_id', 'uq_principals_user_id');
                $table->unique('group_id', 'uq_principals_group_id');
                $table->index('type', 'idx_principals_type');

                $table->foreign('user_id', 'fk_principals_user_id')
                    ->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('group_id', 'fk_principals_group_id')
                    ->references('id')->on('groups')->cascadeOnDelete();
            });
        }

        // Phase 2: DML backfill (transactional). Only the user → principal_id
        // inserts run inside the transaction. The settings tables and
        // agents user_id → principal_id column swap happens in Phase 3
        // (outside the transaction) so the `PRAGMA foreign_keys = OFF`
        // it relies on actually takes effect.

        Capsule::connection()->transaction(static function (): void {
            if (Capsule::connection()->getDriverName() === 'mysql' || Capsule::connection()->getDriverName() === 'mariadb') {
                Capsule::statement(
                    'INSERT IGNORE INTO principals (type, user_id, created_at, updated_at) '
                    . "SELECT 'user', id, NOW(), NOW() FROM users"
                );
            } else {
                Capsule::statement(
                    'INSERT OR IGNORE INTO principals (type, user_id, created_at, updated_at) '
                    . "SELECT 'user', id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM users"
                );
            }
        });

        // Rename user_preferences → principal_preferences first, so the
        // settings-tables loop below can address the table by its new name.
        if ($schema->hasTable('user_preferences') && !$schema->hasTable('principal_preferences')) {
            $schema->rename('user_preferences', 'principal_preferences');
        }

        // Phase 3: column swap (no transaction; PRAGMA needs to take effect).
        // For each settings table, and finally for `agents`:
        //   1. ADD COLUMN principal_id (nullable) + index
        //   2. UPDATE principal_id = user-principal (backfill, idempotent)
        //   3. Promote principal_id to NOT NULL (except llm_driver_configurations)
        //   4. Add FK to principals (on principal_id)
        //   5. Drop the `user_id` column + its FK via a SQLite table-rebuild
        //      (snapshot → DROP → CREATE → INSERT) wrapped in
        //      `PRAGMA foreign_keys = OFF/ON`. The PRAGMA only takes effect
        //      outside a transaction; doing this in Phase 2 would be a no-op
        //      and the DROP would cascade-delete every dependent row.

        $swapSettingsTables = ['llm_driver_configurations', 'tool_user_settings', 'principal_preferences'];

        foreach ($swapSettingsTables as $effective) {
            if (!$schema->hasTable($effective)) {
                continue;
            }
            $hasUserId = $schema->hasColumn($effective, 'user_id');
            $principalIdx = "idx_{$effective}_principal_id";
            $principalFk = "fk_{$effective}_principal_id";

            // 1) Add principal_id column nullable + supporting index. A
            //    previous partial run may have added the column without the
            //    index (or vice versa), so check both before mutating.
            if (!$schema->hasColumn($effective, 'principal_id')) {
                $schema->table($effective, static function (Blueprint $t) use ($principalIdx): void {
                    $t->unsignedBigInteger('principal_id')->nullable()->after('id');
                    $t->index('principal_id', $principalIdx);
                });
            } elseif (!$this->indexExists($effective, $principalIdx)) {
                $schema->table($effective, static function (Blueprint $t) use ($principalIdx): void {
                    $t->index('principal_id', $principalIdx);
                });
            }

            // 2) Backfill principal_id from user-principals. Rows with a
            //    NULL user_id (e.g. global LLM configs) keep principal_id NULL.
            if ($hasUserId) {
                $pairs = Capsule::table('principals')
                    ->where('type', 'user')
                    ->pluck('id', 'user_id')
                    ->all();
                foreach ($pairs as $userId => $principalId) {
                    Capsule::table($effective)
                        ->where('user_id', $userId)
                        ->update(['principal_id' => $principalId]);
                }
            }

            // 3) Promote principal_id to NOT NULL — except llm_driver_configurations
            //    which holds the global config row (is_global = true) and must
            //    keep principal_id = null per LLMDriverConfiguration::validateGlobalXor.
            if ($effective !== 'llm_driver_configurations') {
                $schema->table($effective, static function (Blueprint $t): void {
                    $t->unsignedBigInteger('principal_id')->nullable(false)->change();
                });
            }

            // 4) Add the principal_id FK. Done BEFORE the table rebuild so
            //    Phase 5's CREATE TABLE re-emits this FK alongside the
            //    backfilled data. On MySQL/MariaDB, adding a FK that already
            //    exists errors with errno 121 ("Duplicate key on write or
            //    update") — the helper guards against re-run on a partial
            //    state where the FK add already succeeded previously.
            if (!$this->foreignKeyExists($effective, $principalFk)) {
                $schema->table($effective, static function (Blueprint $t) use ($principalFk): void {
                    $t->foreign('principal_id', $principalFk)
                        ->references('id')->on('principals')
                        ->cascadeOnDelete();
                });
            }

            // 5) Drop the user_id column + its FK. For SQLite, the column
            //    appears in a foreign key definition (`fk_…_user_id`) and
            //    `ALTER TABLE … DROP COLUMN` refuses to drop a column that's
            //    referenced by a FK; the only way is to rebuild the table
            //    via DROP + CREATE. For MySQL/MariaDB we look up the actual
            //    FK and index names (the original migrations used unnamed
            //    FKs that Laravel resolves to `<table>_<col>_foreign`, NOT
            //    the hardcoded `fk_<table>_<col>` pattern) and drop them.
            if ($hasUserId) {
                if ($driver === 'mysql' || $driver === 'mariadb') {
                    // Drop the FK first — it owns the FK column constraint and must
                    // come off before we look up the surviving index. In MariaDB the
                    // explicit `idx_…_user_id` from migration 0011 was reused as the
                    // FK's supporting index (no auto-named index exists), so the
                    // `findIndexOn()` lookup after the drop finds exactly that one.
                    $fkName = $this->findForeignKeyOn($effective, 'user_id');
                    if ($fkName !== null) {
                        Capsule::statement("ALTER TABLE {$effective} DROP FOREIGN KEY {$fkName}");
                    }
                    $idxName = $this->findIndexOn($effective, 'user_id');
                    if ($idxName !== null) {
                        Capsule::statement("ALTER TABLE {$effective} DROP INDEX {$idxName}");
                    }
                    $schema->table($effective, static function (Blueprint $t): void {
                        $t->dropColumn('user_id');
                    });
                } else {
                    $this->rebuildSqliteTableWithoutUserId($effective);
                }
            }
        }

        // Phase 3b: agents table — same dance as the settings tables.
        if (!$schema->hasColumn('agents', 'principal_id')) {
            $schema->table('agents', static function (Blueprint $t): void {
                $t->unsignedBigInteger('principal_id')->nullable()->after('id');
                $t->index('principal_id', 'idx_agents_principal_id');
            });
        } elseif (!$this->indexExists('agents', 'idx_agents_principal_id')) {
            $schema->table('agents', static function (Blueprint $t): void {
                $t->index('principal_id', 'idx_agents_principal_id');
            });
        }

        $userPrincipals = Capsule::table('principals')
            ->where('type', 'user')
            ->pluck('id', 'user_id')
            ->all();
        foreach ($userPrincipals as $userId => $principalId) {
            Capsule::table('agents')
                ->where('user_id', $userId)
                ->update(['principal_id' => $principalId]);
        }

        $missing = (int) Capsule::table('agents')->whereNull('principal_id')->count();
        if ($missing > 0) {
            throw new \RuntimeException(
                "principal_id backfill left {$missing} agent row(s) orphaned — "
                . 'every agents.user_id must resolve to a principal.user_id before this '
                . 'migration can proceed.'
            );
        }

        $schema->table('agents', static function (Blueprint $t): void {
            $t->unsignedBigInteger('principal_id')->nullable(false)->change();
        });

        if ($schema->hasColumn('agents', 'user_id')) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                // Drop the FK first — it owns the FK column constraint and must
                // come off before we look up the surviving index. In MariaDB the
                // explicit `idx_agents_user_id` from migration 0012 was reused as
                // the FK's supporting index (no auto-named index exists), so the
                // `findIndexOn()` lookup after the drop finds exactly that one.
                $fkName = $this->findForeignKeyOn('agents', 'user_id');
                if ($fkName !== null) {
                    Capsule::statement("ALTER TABLE agents DROP FOREIGN KEY {$fkName}");
                }
                $idxName = $this->findIndexOn('agents', 'user_id');
                if ($idxName !== null) {
                    Capsule::statement("ALTER TABLE agents DROP INDEX {$idxName}");
                }
                $schema->table('agents', static function (Blueprint $t): void {
                    $t->dropColumn('user_id');
                });
            } else {
                $this->rebuildSqliteTableWithoutUserId('agents');
            }
        }

        if (!$this->foreignKeyExists('agents', 'fk_agents_principal_id')) {
            $schema->table('agents', static function (Blueprint $t): void {
                $t->foreign('principal_id', 'fk_agents_principal_id')
                    ->references('id')->on('principals')
                    ->restrictOnDelete();
            });
        }
    }

    /** Driver-aware FK existence check used to gate re-runs of this
     *  forward-only migration; the migration runs outside a transaction
     *  (so `PRAGMA foreign_keys = OFF` can take effect during the
     *  `user_id` drop) and a partial failure must be safe to replay. */
    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
                . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . "AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
                [$table, $constraintName]
            );
            return $row !== null;
        }

        // SQLite: PRAGMA foreign_key_list returns anonymous FKs (numeric id),
        // and Laravel's SQLite grammar emits FK declarations WITHOUT a
        // CONSTRAINT name in the CREATE TABLE, so a name match against the
        // table's stored DDL would always miss. Match by the `from` column
        // that the constraint name implies — derive it by stripping the
        // `fk_<table>_` prefix.
        $column = substr($constraintName, strlen("fk_{$table}_"));
        $fks = Capsule::select("PRAGMA foreign_key_list('{$table}')");
        foreach ($fks as $fk) {
            if ($fk->from === $column) {
                return true;
            }
        }
        return false;
    }

    /** Driver-aware index existence check — mirrors foreignKeyExists for
     *  the index side of every guarded step. */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = Capsule::selectOne(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                . 'AND INDEX_NAME = ? LIMIT 1',
                [$table, $indexName]
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

    /** Driver-aware lookup for the FK that references $column on $table.
     *  Returns the constraint name, or null if none. */
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
                [$table, $column]
            );
            return $row?->CONSTRAINT_NAME;
        }

        // SQLite: PRAGMA exposes the auto-assigned numeric FK id, not the
        // constraint name. Returns null — the only caller (the MySQL/MariaDB
        // user_id drop branch) never executes on SQLite.
        return null;
    }

    /** Driver-aware lookup for the index whose leftmost column is $column.
     *  Returns the index name, or null if none. */
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
                [$table, $column]
            );
            return $row?->INDEX_NAME;
        }

        // SQLite: PRAGMA index_list exposes origin ('c' = user-created,
        // 'pk' = PRIMARY KEY, 'f' = FK auto-index). Skip auto indexes so
        // we never return the FK's anonymous id.
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
     * Rebuild a SQLite table without its `user_id` column. The table is
     * snapshotted, dropped, re-created (the `user_id` column and the FK
     * that references `users.id` are omitted), and the snapshot is
     * INSERTed back.
     *
     * This MUST be called with no active transaction. SQLite documents
     * `PRAGMA foreign_keys = OFF` as a no-op inside a transaction
     * (https://sqlite.org/pragma.html pragma_foreign_keys) — without
     * that pragma taking effect, the DROP TABLE would cascade-delete
     * every row in tables that hold `FOREIGN KEY (…_id) REFERENCES
     * {$thisTable}(id) ON DELETE CASCADE` (for `agents`, that is `tasks`,
     * `task_history`, `tool_calls`, `agent_tools`, the override tables,
     * `scheduled_runs`, `scheduled_runs_next`, `agent_prompt_templates`,
     * `agent_pictures`, and `usage` — operator data destroyed in the
     * original implementation).
     *
     * The PRAGMA state is verified immediately after `OFF` and after
     * `ON`; if either pragma is silently ignored (e.g. the connection
     * pool gave us a different connection than the one we set the
     * pragma on) the method throws so the operator sees the failure
     * before the DROP fires rather than after a silent data loss.
     *
     * Public so {@see \Tests\Feature\Database\IntroducePrincipalsAndGroupsMigrationTest}
     * can drive a regression test that creates a `users` ↔ `agents` ↔
     * `tasks` chain with a CASCADE FK from `tasks.agent_id` to `agents.id`,
     * invokes this method on a fresh SQLite db, and asserts the
     * `tasks` row survives the rebuild.
     */
    public function rebuildSqliteTableWithoutUserId(string $effective): void
    {
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
                ($isPk ? ' PRIMARY KEY' : '')
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
                . '(read back %d, expected 0). The DROP TABLE in the next step would '
                . 'cascade-delete dependent rows; aborting the migration rather than '
                . 'risk silent data loss. This usually means Capsule handed a different '
                . 'connection to the PRAGMA and the DROP — file an issue with the full '
                . 'stack trace.',
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
                . '(read back %d, expected 1). Aborting the migration; re-run after the '
                . 'connection-pool issue is resolved.',
                $effective,
                $actual,
            ));
        }
    }

    public function down(): void
    {
        // Forward-only. See class docblock. The data transformations
        // performed by up() (user_preferences → principal_preferences rename,
        // bulk-inserted user-principal rows, FK swap on agents) cannot be
        // losslessly undone.
    }
};
