<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Spora\Core\Database;

/**
 * Migration 0087 rewrites `tool_calls.tool_name` / `tool_calls.tool_class`
 * from `handover` / `Spora\Tools\HandoverTool` to `sub_agent` /
 * `Spora\Tools\SubAgentTool` after the HandoverTool → SubAgentTool rename.
 *
 * Scope is narrow on purpose:
 *   - only the two `tool_calls` columns are rewritten;
 *   - `tasks.data.handover.*` is intentionally left untouched (the
 *     `handover` key on `tasks.data` is the semantic event breadcrumb,
 *     not the tool name).
 *
 * These tests boot a fresh in-memory SQLite, seed a minimal `tasks` /
 * `tool_calls` schema that mirrors the columns the migration touches,
 * then exercise the migration in both directions.
 */
beforeEach(function (): void {
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    // Minimal `tasks` schema — the migration asserts that
    // `tasks.data.handover.*` is left untouched, so we need the column
    // to exist in the shape the migration expects (a JSON-ish text column).
    Capsule::schema()->create('tasks', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->unsignedBigInteger('principal_id')->nullable();
        $t->unsignedBigInteger('trigger_user_id')->nullable();
        $t->string('status', 20);
        $t->text('data')->nullable();
        $t->text('user_prompt')->nullable();
        $t->integer('max_steps')->default(0);
        $t->timestamps();
    });

    // Minimal `tool_calls` schema — only the two columns the migration
    // touches are required, plus a primary key for Capsule::table().
    Capsule::schema()->create('tool_calls', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('task_id')->nullable();
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->string('provider_call_id', 100)->nullable();
        $t->string('tool_name', 100);
        $t->string('tool_class', 200);
        $t->string('tool_type', 10)->nullable();
        $t->string('operation', 50)->nullable();
        $t->string('status', 20)->default('PENDING');
        $t->text('proposed_arguments')->nullable();
        $t->text('result_data')->nullable();
        $t->text('result_content')->nullable();
        $t->timestamps();
    });
});

test('up() rewrites old-shape rows to the new tool_name / tool_class', function (): void {
    $now = date('Y-m-d H:i:s');
    $taskId = (int) Capsule::table('tasks')->insertGetId([
        'status' => 'COMPLETED',
        'data' => json_encode(['handover' => ['target_task_id' => 99]], JSON_THROW_ON_ERROR),
        'user_prompt' => 'old shape',
        'max_steps' => 5,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Three old-shape rows: same tool_name + tool_class pair, distinct ids.
    $oldIds = [];
    for ($i = 0; $i < 3; $i++) {
        $oldIds[] = (int) Capsule::table('tool_calls')->insertGetId([
            'task_id'           => $taskId,
            'provider_call_id'  => "pc_old_{$i}",
            'tool_name'         => 'handover',
            'tool_class'        => 'Spora\\Tools\\HandoverTool',
            'operation'         => 'handover',
            'status'            => 'EXECUTED',
            'proposed_arguments' => json_encode(['op' => 'handover', 'target_agent_id' => 1]),
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    $migration = require __DIR__ . '/../../../database/migrations/0087_rename_handover_tool_to_sub_agent.php';
    $migration->up();

    foreach ($oldIds as $id) {
        $row = Capsule::table('tool_calls')->where('id', $id)->first();
        expect($row->tool_name)->toBe('sub_agent');
        expect($row->tool_class)->toBe('Spora\\Tools\\SubAgentTool');
    }
});

test('down() restores the original tool_name / tool_class pair', function (): void {
    $now = date('Y-m-d H:i:s');
    $taskId = (int) Capsule::table('tasks')->insertGetId([
        'status' => 'COMPLETED',
        'data' => json_encode(['handover' => ['target_task_id' => 99]], JSON_THROW_ON_ERROR),
        'user_prompt' => 'old shape',
        'max_steps' => 5,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $oldIds = [];
    for ($i = 0; $i < 3; $i++) {
        $oldIds[] = (int) Capsule::table('tool_calls')->insertGetId([
            'task_id'           => $taskId,
            'provider_call_id'  => "pc_old_{$i}",
            'tool_name'         => 'handover',
            'tool_class'        => 'Spora\\Tools\\HandoverTool',
            'operation'         => 'handover',
            'status'            => 'EXECUTED',
            'proposed_arguments' => json_encode(['op' => 'handover', 'target_agent_id' => 1]),
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    $migration = require __DIR__ . '/../../../database/migrations/0087_rename_handover_tool_to_sub_agent.php';
    $migration->up();
    $migration->down();

    foreach ($oldIds as $id) {
        $row = Capsule::table('tool_calls')->where('id', $id)->first();
        expect($row->tool_name)->toBe('handover');
        expect($row->tool_class)->toBe('Spora\\Tools\\HandoverTool');
    }
});

test('up() then down() round-trips the rows byte-for-byte', function (): void {
    $now = date('Y-m-d H:i:s');
    $taskId = (int) Capsule::table('tasks')->insertGetId([
        'status' => 'COMPLETED',
        'data' => json_encode(['handover' => ['target_task_id' => 99]], JSON_THROW_ON_ERROR),
        'user_prompt' => 'round-trip',
        'max_steps' => 5,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $oldRows = [];
    for ($i = 0; $i < 3; $i++) {
        $oldRows[] = [
            'id' => (int) Capsule::table('tool_calls')->insertGetId([
                'task_id'           => $taskId,
                'provider_call_id'  => "pc_rt_{$i}",
                'tool_name'         => 'handover',
                'tool_class'        => 'Spora\\Tools\\HandoverTool',
                'operation'         => 'handover',
                'status'            => 'EXECUTED',
                'proposed_arguments' => json_encode(['op' => 'handover', 'target_agent_id' => 1]),
                'created_at'        => $now,
                'updated_at'        => $now,
            ]),
            'tool_name'  => 'handover',
            'tool_class' => 'Spora\\Tools\\HandoverTool',
        ];
    }

    $migration = require __DIR__ . '/../../../database/migrations/0087_rename_handover_tool_to_sub_agent.php';
    $migration->up();
    $migration->down();

    foreach ($oldRows as $expected) {
        $row = Capsule::table('tool_calls')->where('id', $expected['id'])->first();
        expect($row->tool_name)->toBe($expected['tool_name']);
        expect($row->tool_class)->toBe($expected['tool_class']);
    }
});

test('up() leaves tasks.data.handover.* untouched (semantic event breadcrumb is not the tool name)', function (): void {
    $now = date('Y-m-d H:i:s');
    $dataBefore = ['handover' => [
        'target_task_id'   => 99,
        'target_agent_id'  => 11,
        'target_agent_name' => 'Target Agent',
    ]];
    $taskId = (int) Capsule::table('tasks')->insertGetId([
        'status'      => 'COMPLETED',
        'data'        => json_encode($dataBefore, JSON_THROW_ON_ERROR),
        'user_prompt' => 'semantic breadcrumb',
        'max_steps'   => 5,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);

    // A tool_call row that DOES get rewritten — sibling to the task.
    Capsule::table('tool_calls')->insertGetId([
        'task_id'           => $taskId,
        'provider_call_id'  => 'pc_breadcrumb',
        'tool_name'         => 'handover',
        'tool_class'        => 'Spora\\Tools\\HandoverTool',
        'operation'         => 'handover',
        'status'            => 'EXECUTED',
        'proposed_arguments' => json_encode(['op' => 'handover', 'target_agent_id' => 11]),
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0087_rename_handover_tool_to_sub_agent.php';
    $migration->up();

    $task = Capsule::table('tasks')->where('id', $taskId)->first();
    $dataAfter = json_decode($task->data, true);
    expect($dataAfter)->toBe($dataBefore);
});

test('up() is idempotent — re-running against the post-migration schema is a no-op', function (): void {
    $now = date('Y-m-d H:i:s');
    $taskId = (int) Capsule::table('tasks')->insertGetId([
        'status' => 'COMPLETED',
        'data' => json_encode(['handover' => ['target_task_id' => 99]], JSON_THROW_ON_ERROR),
        'user_prompt' => 'idempotent',
        'max_steps' => 5,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Capsule::table('tool_calls')->insertGetId([
        'task_id'           => $taskId,
        'provider_call_id'  => 'pc_idem',
        'tool_name'         => 'handover',
        'tool_class'        => 'Spora\\Tools\\HandoverTool',
        'operation'         => 'handover',
        'status'            => 'EXECUTED',
        'proposed_arguments' => json_encode(['op' => 'handover', 'target_agent_id' => 1]),
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0087_rename_handover_tool_to_sub_agent.php';
    $migration->up();
    $migration->up();

    $row = Capsule::table('tool_calls')->where('provider_call_id', 'pc_idem')->first();
    expect($row->tool_name)->toBe('sub_agent');
    expect($row->tool_class)->toBe('Spora\\Tools\\SubAgentTool');
});

test('up() does not touch rows that already use the new tool_name (no over-rewrite)', function (): void {
    $now = date('Y-m-d H:i:s');
    $taskId = (int) Capsule::table('tasks')->insertGetId([
        'status' => 'COMPLETED',
        'data' => null,
        'user_prompt' => 'untouched',
        'max_steps' => 5,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // A row already on the new tool_name + tool_class. Migration must
    // leave it alone — gating on both columns prevents a re-upgrade from
    // touching post-upgrade rows.
    $id = (int) Capsule::table('tool_calls')->insertGetId([
        'task_id'           => $taskId,
        'provider_call_id'  => 'pc_already_new',
        'tool_name'         => 'sub_agent',
        'tool_class'        => 'Spora\\Tools\\SubAgentTool',
        'operation'         => 'handover',
        'status'            => 'EXECUTED',
        'proposed_arguments' => json_encode(['op' => 'handover', 'target_agent_id' => 1]),
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0087_rename_handover_tool_to_sub_agent.php';
    $migration->up();

    $row = Capsule::table('tool_calls')->where('id', $id)->first();
    expect($row->tool_name)->toBe('sub_agent');
    expect($row->tool_class)->toBe('Spora\\Tools\\SubAgentTool');
});
