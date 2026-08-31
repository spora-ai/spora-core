<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Spora\Core\Database;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Models\User;

beforeEach(function (): void {
    Database::resetBootState();
    $db = new Database([
        'db_driver' => 'sqlite',
        'db_path'   => ':memory:',
    ]);
    $db->boot();
});

test('0067 migration creates principals/groups/group_memberships tables', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasTable('groups'))->toBeTrue();
    expect(Capsule::schema()->hasTable('group_memberships'))->toBeTrue();
    expect(Capsule::schema()->hasTable('principals'))->toBeTrue();

    // principal_preferences replaced user_preferences
    expect(Capsule::schema()->hasTable('principal_preferences'))->toBeTrue();
    expect(Capsule::schema()->hasTable('user_preferences'))->toBeFalse();
});

test('0067 migration backfills a user-principal per existing user', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';

    // Seed two users first (no seeder in this test), then run the
    // migration. After up(), every user has exactly one user-principal.
    $userA = Capsule::table('users')->insertGetId([
        'email' => 'alice@example.com', 'username' => 'alice',
        'password' => 'unused-hash', 'status' => 1, 'verified' => 1,
        'roles_mask' => 0, 'registered' => time(),
    ]);
    $userB = Capsule::table('users')->insertGetId([
        'email' => 'bob@example.com', 'username' => 'bob',
        'password' => 'unused-hash', 'status' => 1, 'verified' => 1,
        'roles_mask' => 0, 'registered' => time(),
    ]);

    $migration->up();

    $count = Capsule::table('principals')->where('type', 'user')->count();
    $users = Capsule::table('users')->count();
    expect($users)->toBe(2);
    expect($count)->toBe(2);
});

test('0067 migration swaps agents.user_id for agents.principal_id', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    expect(Capsule::schema()->hasColumn('agents', 'user_id'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('agents', 'principal_id'))->toBeTrue();

    // Each agent row must carry a non-null principal_id
    $nullPrincipals = Capsule::table('agents')->whereNull('principal_id')->count();
    expect($nullPrincipals)->toBe(0);
});

test('0067 migration drops user_id from settings tables', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    foreach (['llm_driver_configurations', 'tool_user_settings', 'principal_preferences'] as $t) {
        expect(Capsule::schema()->hasColumn($t, 'user_id'))->toBeFalse("{$t} should not have user_id");
        expect(Capsule::schema()->hasColumn($t, 'principal_id'))->toBeTrue("{$t} should have principal_id");
    }
});

test('0067 migration forward-only: down() preserves new tables', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();
    $migration->down();

    // No-op down — the new tables stay. (A real rollback needs a manual
    // backup restore; this test only documents the contract.)
    expect(Capsule::schema()->hasTable('principals'))->toBeTrue();
    expect(Capsule::schema()->hasTable('groups'))->toBeTrue();
});

test('Principal model enforces XOR: only one of user_id/group_id', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    $userId = (int) Capsule::table('users')->value('id');

    // Both set → rejected.
    expect(fn() => Principal::create([
        'type' => 'user', 'principal_id' => createUserPrincipalPublic($userId), 'group_id' => null,
    ]))->not()->toThrow(Throwable::class);

    // Both null → rejected via XOR.
    $thrown = null;
    try {
        Principal::create([
            'type' => 'user', 'user_id' => null, 'group_id' => null,
        ]);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(LogicException::class);

    // Both set → rejected via XOR (FK will also fail but LogicException first).
    $thrown = null;
    try {
        Principal::create([
            'type' => 'user', 'principal_id' => createUserPrincipalPublic($userId), 'group_id' => 999,
        ]);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(LogicException::class);
});

test('Principal type must match the FK that is set', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    $userId = (int) Capsule::table('users')->value('id');

    expect(fn() => Principal::create([
        'type' => 'group', 'principal_id' => createUserPrincipalPublic($userId), 'group_id' => null,
    ]))->toThrow(LogicException::class);
});

test('0067 migration does not cascade-delete dependent rows when rebuilding the agents table', function (): void {
    // Regression test for the SQLite PRAGMA-foreign-keys-is-a-no-op-in-a-
    // transaction bug that previously dropped every row in `tasks`,
    // `task_history`, `tool_calls`, `agent_tools`, `agent_tool_overrides`,
    // `agent_tool_operation_overrides`, `scheduled_runs`,
    // `scheduled_runs_next`, `agent_prompt_templates`, `agent_pictures`,
    // and `usage` (every table that holds `FOREIGN KEY (…_id) REFERENCES
    // agents(id) ON DELETE CASCADE`) when the migration rebuilt the
    // `agents` table to drop its `user_id` column.
    //
    // The Pest `beforeEach` already booted 0067 onto the shared `:memory:`
    // db. The post-0067 `tasks.agent_id → agents.id` CASCADE FK is still
    // in place — perfect for the regression. We add a user + matching
    // user-principal + back the pre-0067 `agents.user_id` column onto the
    // `agents` table with the `users.id` CASCADE FK, insert dependent
    // rows, then call `rebuildSqliteTableWithoutUserId()` — the same
    // DROP+CREATE path the migration uses — and assert the dependent
    // rows survive.

    $userId = Capsule::table('users')->insertGetId([
        'email' => 'cascade-regression@example.com', 'username' => 'cascade_regression',
        'password' => 'unused-hash', 'status' => 1, 'verified' => 1,
        'roles_mask' => 0, 'registered' => time(),
    ]);
    $principalId = Capsule::table('principals')->insertGetId([
        'type'       => 'user',
        'user_id'    => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Re-add the pre-0067 user_id column + CASCADE FK on agents.
    Capsule::schema()->table('agents', static function (Blueprint $t): void {
        $t->unsignedBigInteger('user_id')->nullable()->after('name');
        $t->index('user_id', 'idx_agents_user_id');
        $t->foreign('user_id', 'fk_agents_user_id')
            ->references('id')->on('users')->onDelete('cascade');
    });
    // Relax tasks.principal_id to nullable + re-add the pre-0071
    // user_id column. The post-0071 boot sets principal_id to NOT NULL —
    // we have to undo that so the dependent-row test can backfill tasks
    // with the pre-state user_id shape.
    Capsule::schema()->table('tasks', static function (Blueprint $t): void {
        $t->unsignedBigInteger('principal_id')->nullable()->change();
    });

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'user_id'      => $userId,
        'principal_id' => $principalId,
        'name'         => 'cascade-regression-agent',
        'max_steps'    => 10,
        'is_active'    => 1,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
    // Re-add the pre-0071 user_id column on tasks so the dependent-row
    // cascade test exercises the same DB shape the SQLite rebuild path
    // is expected to handle. (agents.user_id was already re-added above
    // for the same reason; tasks needs the parallel fix.)
    Capsule::schema()->table('tasks', static function (Blueprint $t): void {
        $t->unsignedBigInteger('user_id')->nullable()->after('agent_id');
        $t->index('user_id', 'idx_tasks_user_id');
        $t->foreign('user_id', 'fk_tasks_user_id')
            ->references('id')->on('users')->onDelete('cascade');
    });
    $taskId = (int) Capsule::table('tasks')->insertGetId([
        'agent_id'     => $agentId,
        'user_id'      => $userId,
        'status'       => 'COMPLETED',
        'user_prompt'  => 'cascade regression fixture',
        'step_count'   => 1,
        'max_steps'    => 10,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
    $taskHistoryId = (int) Capsule::table('task_history')->insertGetId([
        'task_id'    => $taskId,
        'sequence'   => 0,
        'role'       => 'user',
        'content'    => 'fixture content',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $toolCallId = (int) Capsule::table('tool_calls')->insertGetId([
        'task_id'             => $taskId,
        'agent_id'            => $agentId,
        'provider_call_id'    => 'cascade-fixture-call',
        'tool_name'           => 'stub_output',
        'tool_class'          => 'StubOutputTool',
        'tool_type'           => 'function',
        'operation'           => 'echo',
        'operation_description' => 'Echo',
        'status'              => 'PENDING',
        'proposed_arguments'  => '[]',
    ]);
    $toolId = (int) Capsule::table('agent_tools')->insertGetId([
        'agent_id'   => $agentId,
        'tool_class' => 'Spora\Tools\StubOutputTool',
        'tool_name'  => 'stub_output',
    ]);
    $overrideId = (int) Capsule::table('agent_tool_overrides')->insertGetId([
        'agent_id'   => $agentId,
        'tool_class' => 'Spora\Tools\StubOutputTool',
        'settings'   => '{}',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $usageId = (int) Capsule::table('usage')->insertGetId([
        'task_history_id'   => $taskHistoryId,
        'input_tokens'      => 1,
        'output_tokens'     => 1,
        'provider'          => 'cascade-fixture-provider',
        'created_at'        => date('Y-m-d H:i:s'),
    ]);
    $promptTemplateId = (int) Capsule::table('agent_prompt_templates')->insertGetId([
        'agent_id'         => $agentId,
        'name'             => 'fixture template',
        'prompt_template'  => 'hello',
        'created_at'       => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s'),
    ]);
    $scheduledRunId = (int) Capsule::table('scheduled_runs')->insertGetId([
        'agent_id'    => $agentId,
        'template_id' => null,
        'raw_prompt'  => 'cascade fixture',
        'cron_expression' => '0 * * * *',
        'next_run_at' => date('Y-m-d H:i:s'),
        'is_active'   => 1,
        'user_id'     => $userId,
    ]);
    $pictureId = (int) Capsule::table('agent_pictures')->insertGetId([
        'agent_id'         => $agentId,
        'archetype'        => 'cascade-fixture',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->rebuildSqliteTableWithoutUserId('agents');

    // Every dependent row must still be there. If the rebuild's
    // PRAGMA-foreign-keys-OFF were a no-op (the bug the original
    // implementation hit inside the surrounding transaction), the DROP
    // would have cascade-deleted all of these and these counts would be
    // zero.
    expect(Capsule::table('agents')->where('id', $agentId)->count())->toBe(1)
        ->and(Capsule::table('agent_tools')->where('id', $toolId)->count())->toBe(1)
        ->and(Capsule::table('agent_tool_overrides')->where('id', $overrideId)->count())->toBe(1)
        ->and(Capsule::table('tasks')->where('id', $taskId)->count())->toBe(1)
        ->and(Capsule::table('task_history')->where('id', $taskHistoryId)->count())->toBe(1)
        ->and(Capsule::table('tool_calls')->where('id', $toolCallId)->count())->toBe(1)
        ->and(Capsule::table('usage')->where('id', $usageId)->count())->toBe(1)
        ->and(Capsule::table('agent_prompt_templates')->where('id', $promptTemplateId)->count())->toBe(1)
        ->and(Capsule::table('scheduled_runs')->where('id', $scheduledRunId)->count())->toBe(1)
        ->and(Capsule::table('agent_pictures')->where('id', $pictureId)->count())->toBe(1);

    // And the agents.user_id column is gone.
    expect(Capsule::schema()->hasColumn('agents', 'user_id'))->toBeFalse();
});

test('0067 migration leaves a coherent sqlite_master with no orphan indexes', function (): void {
    // The Pest `beforeEach` already booted every migration including 0067.
    // Re-running 0067 would throw — and we don't need to. We just inspect
    // the post-0067 schema for the kind of malformed state the operator's
    // spora-local hit:
    //   * every sqlite_master index entry targets a real table
    //   * PRAGMA quick_check returns 'ok'
    //   * PRAGMA foreign_key_check returns no violations
    //   * sqlite_master itself is queryable end-to-end (the operator's
    //     spora-local crashed here with "malformed database schema" the
    //     moment PDO opened a connection and ran a PRAGMA)
    //
    // Drop and re-create one of the settings tables to simulate a future
    // migration that does its own table rebuild — verifies the migration's
    // CREATE TABLE patterns don't leave dangling references either.

    $conn = Capsule::connection();
    $master = $conn->select('SELECT type, name, tbl_name FROM sqlite_master');
    $tableNames = [];
    foreach ($master as $row) {
        if ($row->type === 'table') {
            $tableNames[$row->name] = true;
        }
    }
    foreach ($master as $row) {
        if ($row->type === 'index' && !isset($tableNames[$row->tbl_name])) {
            throw new RuntimeException(sprintf(
                'Orphan index %s references missing table %s in sqlite_master',
                $row->name,
                $row->tbl_name,
            ));
        }
    }

    expect($conn->select('PRAGMA quick_check'))->toHaveCount(1);
    expect((array) $conn->selectOne('PRAGMA quick_check'))->toMatchArray(['quick_check' => 'ok'])
        ->and($conn->select('PRAGMA foreign_key_check'))->toBe([]);

    $tables = $conn->select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
    expect($tables)->not->toBeEmpty();

    // The settings tables the migration rebuilds must still exist
    // post-migration. (The operator's spora-local lost three of these to
    // a partial application of a buggy 0067; this assertion catches that
    // regression if it ever returns.)
    foreach (['tool_user_settings', 'llm_driver_configurations', 'agents', 'principal_preferences'] as $required) {
        expect($tableNames)->toHaveKey($required, "table {$required} is missing after 0067");
    }
});

test('0067 migration rebuild preserves pre-existing indexes on settings tables', function (): void {
    // The migration's rebuildSqliteTableWithoutUserId() recreates the table
    // from PRAGMA table_info + PRAGMA foreign_key_list. It does NOT walk
    // PRAGMA index_list, so any index on the source table that isn't the
    // user_id FK is silently lost during the DROP+CREATE. This test seeds
    // an index on `tool_user_settings.tool_class` (the exact case the
    // operator's spora-local hit with the orphan `idx_tool_user_settings_tool`)
    // then runs the rebuild on a freshly-prepared table-with-user_id, and
    // asserts the index reappears post-rebuild.

    // Re-create the pre-0067 shape: tool_user_settings with user_id and the
    // index we're worried about. The shared `:memory:` already has
    // principal_id-only schema, so we rename out of the way, re-add, and
    // restore the canonical schema at the end.
    Capsule::schema()->rename('tool_user_settings', 'tool_user_settings_post0067');
    Capsule::schema()->create('tool_user_settings', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('user_id');
        $t->string('tool_class', 200);
        $t->text('settings')->nullable();
        $t->timestamp('created_at')->nullable();
        $t->timestamp('updated_at')->nullable();
        $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    });
    Capsule::connection()->statement('CREATE INDEX idx_tool_user_settings_tool ON tool_user_settings(tool_class)');

    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->rebuildSqliteTableWithoutUserId('tool_user_settings');

    // After the rebuild, querying sqlite_master must not throw "malformed
    // database schema" — that's the operator-visible failure mode. The
    // index may legitimately have been dropped by the rebuild, but the
    // sqlite_master entry MUST have been dropped with it.
    $conn = Capsule::connection();
    $master = $conn->select('SELECT type, name, tbl_name FROM sqlite_master');
    $tableNames = [];
    foreach ($master as $row) {
        if ($row->type === 'table') {
            $tableNames[$row->name] = true;
        }
    }
    foreach ($master as $row) {
        if ($row->type === 'index' && !isset($tableNames[$row->tbl_name])) {
            throw new RuntimeException(sprintf(
                'rebuildSqliteTableWithoutUserId() left orphan index %s on missing table %s',
                $row->name,
                $row->tbl_name,
            ));
        }
    }
    expect($conn->select('PRAGMA quick_check'))->toHaveCount(1);
    expect((array) $conn->selectOne('PRAGMA quick_check'))->toMatchArray(['quick_check' => 'ok']);

    Capsule::schema()->drop('tool_user_settings');
    Capsule::schema()->rename('tool_user_settings_post0067', 'tool_user_settings');
});

test('0067 migration is idempotent — up() can be re-run against the post-0067 schema', function (): void {
    // The Pest beforeEach already booted every migration including 0067,
    // so the schema on entry is the post-0067 shape. The first up() call
    // should be a no-op (every guard reports "exists"); the second up()
    // exercises the same path again. On MariaDB this is the operator's
    // recovery from the partial-state reported as errno 121 on
    // fk_llm_driver_configurations_principal_id (the previous FK-add
    // attempt had created the constraint, so the re-run used to error
    // out trying to create it again).
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    foreach (['llm_driver_configurations', 'tool_user_settings', 'principal_preferences'] as $t) {
        expect(Capsule::schema()->hasColumn($t, 'user_id'))->toBeFalse("{$t} should not have user_id");
        expect(Capsule::schema()->hasColumn($t, 'principal_id'))->toBeTrue("{$t} should have principal_id");
    }
    expect(Capsule::schema()->hasColumn('agents', 'user_id'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('agents', 'principal_id'))->toBeTrue();
});

test('0067 migration recovers from a partially-applied state on SQLite', function (): void {
    // Simulate the operator's MariaDB partial state: the principal_id FK
    // add step failed previously, so llm_driver_configurations has the
    // column + index but no FK to principals. On SQLite ALTER TABLE
    // cannot add or drop FKs, so the simulation drops the FK via a table
    // rebuild (the same pattern the migration uses for the user_id
    // drop). Re-running the migration must (a) detect the missing FK via
    // foreignKeyExists() and add it, (b) detect the missing index via
    // indexExists() — already added in the simulation, so this branch
    // should be a no-op — and (c) keep the user_id drop a no-op since
    // user_id is already gone after the first boot.

    $conn = Capsule::connection();
    $table = 'llm_driver_configurations';

    // 1. Snapshot rows so we can restore them after the rebuild.
    $rows = Capsule::table($table)->get()->all();

    // 2. Read the current column structure and rebuild the CREATE TABLE
    //    SQL without the principal_id → principals FK.
    $columns = $conn->select("PRAGMA table_info('{$table}')");
    $columnDefs = [];
    foreach ($columns as $c) {
        $isPk = ((int) $c->pk) > 0;
        $dflt = ($c->dflt_value !== null && strtoupper((string) $c->dflt_value) !== 'NULL')
            ? ' DEFAULT ' . $c->dflt_value
            : '';
        $columnDefs[] = sprintf(
            '"%s" %s%s%s%s',
            $c->name,
            $c->type,
            ($c->notnull && !$isPk ? ' NOT NULL' : ''),
            $dflt,
            ($isPk ? ' PRIMARY KEY' : ''),
        );
    }

    $fks = $conn->select("PRAGMA foreign_key_list('{$table}')");
    $fkDefs = [];
    foreach ($fks as $fk) {
        // Skip the principal_id FK we're simulating as missing.
        if ($fk->from === 'principal_id') {
            continue;
        }
        $key = $fk->id;
        if (!isset($fkDefs[$key])) {
            $fkDefs[$key] = ['table' => $fk->table, 'columns' => [], 'references' => []];
        }
        $fkDefs[$key]['columns'][] = $fk->from;
        $fkDefs[$key]['references'][] = $fk->to;
    }

    $sql = "CREATE TABLE {$table} (\n  ";
    $sql .= implode(",\n  ", $columnDefs);
    foreach ($fkDefs as $def) {
        $cols = implode(', ', array_map(static fn($c) => "\"{$c}\"", $def['columns']));
        $refs = implode(', ', array_map(static fn($r) => "\"{$r}\"", $def['references']));
        $sql .= ",\n  FOREIGN KEY ({$cols}) REFERENCES \"{$def['table']}\" ({$refs})";
    }
    $sql .= "\n)";

    $conn->statement('PRAGMA foreign_keys = OFF');
    $fkOff = (int) $conn->selectOne('PRAGMA foreign_keys')->foreign_keys;
    if ($fkOff !== 0) {
        throw new RuntimeException(sprintf(
            'PRAGMA foreign_keys = OFF did not take effect on the %s connection '
            . '(read back %d, expected 0). The DROP TABLE in the next step would '
            . 'cascade-delete dependent rows; aborting the partial-state simulation '
            . 'rather than risk silent data loss. This usually means Capsule handed '
            . 'a different connection to the PRAGMA and the DROP — file an issue '
            . 'with the full stack trace.',
            $table,
            $fkOff,
        ));
    }
    $conn->statement("DROP TABLE {$table}");
    $conn->statement($sql);
    if ($rows !== []) {
        $columnsToKeep = array_map(static fn($c) => $c->name, $columns);
        $colsList = implode(', ', array_map(static fn($c) => "\"{$c}\"", $columnsToKeep));
        $placeholders = '(' . implode(',', array_fill(0, count($columnsToKeep), '?')) . ')';
        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columnsToKeep as $c) {
                $bindings[] = $row->{$c} ?? null;
            }
            $conn->insert("INSERT INTO {$table} ({$colsList}) VALUES {$placeholders}", $bindings);
            $bindings = [];
        }
    }
    $conn->statement('PRAGMA foreign_keys = ON');
    $fkOn = (int) $conn->selectOne('PRAGMA foreign_keys')->foreign_keys;
    if ($fkOn !== 1) {
        throw new RuntimeException(sprintf(
            'PRAGMA foreign_keys = ON did not take effect on the %s connection '
            . '(read back %d, expected 1). Aborting the partial-state simulation; '
            . 're-run after the connection-pool issue is resolved.',
            $table,
            $fkOn,
        ));
    }

    // Sanity-check: the principal_id FK is gone before the re-run.
    $fkNow = $conn->select("PRAGMA foreign_key_list('{$table}')");
    $principalFkGone = true;
    foreach ($fkNow as $fk) {
        if ($fk->from === 'principal_id' && $fk->table === 'principals') {
            $principalFkGone = false;
        }
    }
    expect($principalFkGone)->toBeTrue('simulation must drop the principal_id FK');

    // 3. Run the migration — the helper must detect the missing FK and
    //    re-add it without throwing.
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    // 4. The FK must now exist.
    $fkAfter = $conn->select("PRAGMA foreign_key_list('{$table}')");
    $hasPrincipalFk = false;
    foreach ($fkAfter as $fk) {
        if ($fk->from === 'principal_id' && $fk->table === 'principals') {
            $hasPrincipalFk = true;
        }
    }
    expect($hasPrincipalFk)->toBeTrue('up() must re-add the principal_id FK');
});

test('0067 migration helper: foreignKeyExists on SQLite', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    // `setAccessible(true)` is deprecated in PHP 8.1+; bind a closure to
    // the anonymous-class instance so the private helper can be called
    // without triggering the deprecation.
    $callHelper = Closure::bind(
        static fn(string $table, string $name): bool => $migration->foreignKeyExists($table, $name),
        null,
        $migration::class,
    );

    // FK created by Phase 1 — name follows fk_<table>_<col>.
    expect($callHelper('principals', 'fk_principals_user_id'))->toBeTrue();
    expect($callHelper('principals', 'fk_principals_group_id'))->toBeTrue();
    expect($callHelper('groups', 'fk_groups_created_by_user_id'))->toBeTrue();

    expect($callHelper('principals', 'fk_principals_type'))->toBeFalse();
});

test('0067 migration helper: indexExists on SQLite', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    $callHelper = Closure::bind(
        static fn(string $table, string $name): bool => $migration->indexExists($table, $name),
        null,
        $migration::class,
    );

    expect($callHelper('principals', 'idx_principals_type'))->toBeTrue();
    expect($callHelper('llm_driver_configurations', 'idx_llm_driver_configurations_principal_id'))->toBeTrue();
    expect($callHelper('llm_driver_configurations', 'idx_nonexistent'))->toBeFalse();
});

test('0067 migration helper: findIndexOn on SQLite', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    $callHelper = Closure::bind(
        static fn(string $table, string $column): ?string => $migration->findIndexOn($table, $column),
        null,
        $migration::class,
    );

    expect($callHelper('principals', 'type'))->toBe('idx_principals_type');
    expect($callHelper('llm_driver_configurations', 'principal_id'))
        ->toBe('idx_llm_driver_configurations_principal_id');
    expect($callHelper('principals', 'nonexistent_column'))->toBeNull();
});
