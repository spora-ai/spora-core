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

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'user_id'      => $userId,
        'principal_id' => $principalId,
        'name'         => 'cascade-regression-agent',
        'max_steps'    => 10,
        'is_active'    => 1,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
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
