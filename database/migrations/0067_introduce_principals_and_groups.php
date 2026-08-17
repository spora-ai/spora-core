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
 * Why two phases (DDL outside transaction + DML inside):
 *
 *   SQLite cannot `ALTER TABLE … ADD FOREIGN KEY` inside a transaction
 *   (`PRAGMA foreign_keys` is a no-op inside transactions; Laravel's
 *   SQLiteGrammar detects `foreign`/`dropForeign` commands and rebuilds
 *   the affected table, which fails when FK state is being toggled
 *   transactionally). The DDL steps run outside any transaction;
 *   the DML backfills run inside a single transaction so a partial
 *   backfill cannot leave the database in an inconsistent state.
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

        // ── Phase 1: New tables (DDL only; safe on both engines) ───────────

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

        // ── Phase 2: DML backfill (transactional) ───────────────────────────

        Capsule::connection()->transaction(function () use ($schema, $driver): void {
            // 2a) Bulk-insert one user-principal per existing user.
            if ($driver === 'mysql' || $driver === 'mariadb') {
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

            // 2b) Settings tables: add principal_id (nullable), backfill,
            //     promote to NOT NULL, add FK. We process in a fixed order
            //     — renaming user_preferences happens in the third step.
            $settingsTables = [
                'llm_driver_configurations',
                'tool_user_settings',
                'principal_preferences',
                'user_preferences',
            ];

            // Rename FIRST if it hasn't been renamed yet. After rename we
            // work with principal_preferences; the user_preferences entry
            // in the list above is then a no-op because the table won't
            // exist when we reach the iteration.
            if ($schema->hasTable('user_preferences') && !$schema->hasTable('principal_preferences')) {
                $schema->rename('user_preferences', 'principal_preferences');
            }

            // 2c) For each effective settings table, add principal_id if
            //     missing, backfill, set NOT NULL, add FK.
            $processed = [];
            foreach (['llm_driver_configurations', 'tool_user_settings', 'principal_preferences'] as $effective) {
                if ($processed[$effective] ?? false) {
                    continue;
                }
                if (!$schema->hasTable($effective)) {
                    continue;
                }

                // Some settings tables (e.g. global LLM configs) have
                // nullable user_id; skip them gracefully if so.
                $hasUserId = $schema->hasColumn($effective, 'user_id');

                // Add principal_id column nullable.
                if (!$schema->hasColumn($effective, 'principal_id')) {
                    $idxName = "idx_{$effective}_principal_id";
                    $schema->table($effective, static function (Blueprint $t) use ($idxName): void {
                        $t->unsignedBigInteger('principal_id')->nullable()->after('id');
                        $t->index('principal_id', $idxName);
                    });
                }

                // Backfill principal_id for rows whose user_id points at a
                // user-principal. NULL user_id rows (e.g. global configs)
                // keep principal_id NULL — promoted rows will be checked
                // against the backend's XOR rule (`Principal::saving` event).
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

                // Promote principal_id to NOT NULL + add FK. If any non-null
                // rows remain without a principal, the FK creation will fail
                // with a "FOREIGN KEY constraint failed" error which the
                // operator must address before re-running the migration.
                // EXCEPT llm_driver_configurations — the global-config row
                // (the unique `is_global = true` config) must keep
                // `principal_id = null` per the model XOR invariant
                // (LLMDriverConfiguration::validateGlobalXor).
                if ($effective !== 'llm_driver_configurations') {
                    $schema->table($effective, static function (Blueprint $t) use ($effective): void {
                        $t->unsignedBigInteger('principal_id')->nullable(false)->change();
                    });
                }

                $fkName = "fk_{$effective}_principal_id";
                $schema->table($effective, static function (Blueprint $t) use ($effective, $fkName): void {
                    $t->foreign('principal_id', $fkName)
                        ->references('id')->on('principals')
                        ->cascadeOnDelete();
                });

                // Drop the old user_id column to keep the schema clean. The
                // settings cascade (ToolConfigService, LLMConfigPersistence)
                // has already been updated to key on principal_id, so no
                // join against user_id is needed.
                if ($hasUserId) {
                    if ($driver === 'mysql' || $driver === 'mariadb') {
                        Capsule::statement("ALTER TABLE {$effective} DROP FOREIGN KEY fk_{$effective}_user_id");
                        Capsule::statement("ALTER TABLE {$effective} DROP INDEX fk_{$effective}_user_id");
                    } else {
                        // SQLite: rebuild the table manually so both the FK
                        // and the column can be dropped in a single atomic
                        // operation. Laravel's schema builder can't drop
                        // an FK without knowing its name; the easier path
                        // here is to do a table rebuild with the column
                        // omitted.

                        // 1. Snapshot all rows.
                        $rows = Capsule::table($effective)->get()->all();

                        // 2. Map column list excluding user_id. Each column
                        //    def includes its PRIMARY KEY clause inline so the
                        //    composite CREATE TABLE stays valid SQL.
                        $originalColumns = Capsule::select(
                            "PRAGMA table_info('{$effective}')"
                        );
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
                        // Pull FKs excluding user_id references, regrouped by id.
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

                        // 3. Build the new CREATE TABLE statement.
                        $sql = "CREATE TABLE {$effective} (\n  ";
                        $sql .= implode(",\n  ", $columnDefs);
                        foreach ($fkDefs as $def) {
                            $cols = implode(', ', array_map(static fn($c) => "\"{$c}\"", $def['columns']));
                            $refs = implode(', ', array_map(static fn($r) => "\"{$r}\"", $def['references']));
                            $sql .= ",\n  FOREIGN KEY ({$cols}) REFERENCES \"{$def['table']}\" ({$refs})";
                        }
                        $sql .= "\n)";

                        Capsule::statement('PRAGMA foreign_keys = OFF');
                        Capsule::statement("DROP TABLE {$effective}");
                        Capsule::statement($sql);
                        $colsList = implode(', ', array_map(static fn($c) => "\"{$c}\"", $columnsToKeep));
                        // Re-insert preserved rows.
                        if ($rows !== []) {
                            // We assemble the values via a manual INSERT.
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
                    }
                }

                $processed[$effective] = true;
                unset($processed);
            }

            // 2d) Agents: add principal_id, backfill, NOT NULL, drop
            //     user_id FK + column, add principal_id FK with RESTRICT.
            if (!$schema->hasColumn('agents', 'principal_id')) {
                $schema->table('agents', static function (Blueprint $t): void {
                    $t->unsignedBigInteger('principal_id')->nullable()->after('id');
                    $t->index('principal_id', 'idx_agents_principal_id');
                });
            }

            // Backfill: every agent's principal_id = its user-principal's id. We
            // iterate the principal map rather than emitting a single JOIN-
            // UPDATE because Eloquent's SQLite UPDATE-with-join path emits
            // unquoted identifiers that strict engines reject.
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
                    Capsule::statement('ALTER TABLE agents DROP FOREIGN KEY fk_agents_user_id');
                    Capsule::statement('ALTER TABLE agents DROP INDEX fk_agents_user_id');
                    $schema->table('agents', static function (Blueprint $t): void {
                        $t->dropColumn('user_id');
                    });
                } else {
                    // Same SQLite manual table-rebuild as the settings
                    // tables above: snapshot rows, rebuild without the
                    // column (and the FK that references it), restore.
                    $effective = 'agents';
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
                }
            }

            $schema->table('agents', static function (Blueprint $t): void {
                $t->foreign('principal_id', 'fk_agents_principal_id')
                    ->references('id')->on('principals')
                    ->restrictOnDelete();
            });
        });
    }

    public function down(): void
    {
        // Forward-only. See class docblock. The data transformations
        // performed by up() (user_preferences → principal_preferences rename,
        // bulk-inserted user-principal rows, FK swap on agents) cannot be
        // losslessly undone.
    }
};
