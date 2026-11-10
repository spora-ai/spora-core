<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Principal;

/**
 * Migration 0101 — the `user_info` tool becomes `principal_info`.
 *
 * Every table that keys a configured tool carries the class name, and a row
 * that still names the old class is a row the container can no longer
 * resolve: the tool silently disappears from the agent that had it. The
 * audit trail in `tool_calls` is the one place the old name must survive —
 * it records what actually ran, and what ran was `UserInfoTool`.
 */
beforeEach(function (): void {
    $this->userId = (int) Capsule::table('users')->insertGetId([
        'email'      => 'rename-tool@example.com',
        'password'   => str_repeat("\0", 60),
        'username'   => 'renamer',
        'status'     => 0,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $this->principalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_USER,
        'user_id'    => $this->userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $this->agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $this->principalId,
        'name'         => 'Test Agent',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $this->taskId = (int) Capsule::table('tasks')->insertGetId([
        'agent_id'     => $this->agentId,
        'principal_id' => $this->principalId,
        'status'       => 'PENDING',
        'user_prompt'  => 'Hello',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    Capsule::table('tool_configurations')->insert([
        'tool_class' => 'Spora\\Tools\\UserInfoTool',
        'tool_name'  => 'user_info',
        'settings'   => '{}',
    ]);

    Capsule::table('agent_tools')->insert([
        'agent_id'   => $this->agentId,
        'tool_class' => 'Spora\\Tools\\UserInfoTool',
        'tool_name'  => 'user_info',
    ]);

    Capsule::table('agent_tool_overrides')->insert([
        'agent_id'   => $this->agentId,
        'tool_class' => 'Spora\\Tools\\UserInfoTool',
        'settings'   => '{}',
    ]);

    Capsule::table('agent_tool_operation_overrides')->insert([
        'agent_id'   => $this->agentId,
        'tool_class' => 'Spora\\Tools\\UserInfoTool',
        'operation'  => 'get_health_data',
    ]);

    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $this->principalId,
        'tool_class'   => 'Spora\\Tools\\UserInfoTool',
        'settings'     => '{}',
    ]);

    Capsule::table('tool_calls')->insert([
        'task_id'            => $this->taskId,
        'agent_id'           => $this->agentId,
        'provider_call_id'   => 'call_1',
        'tool_name'          => 'user_info',
        'tool_class'         => 'Spora\\Tools\\UserInfoTool',
        'tool_type'          => 'output',
        'proposed_arguments' => '{}',
    ]);

    $this->migration = require __DIR__ . '/../../../database/migrations/0101_rename_user_info_tool_to_principal_info.php';
});

test('every table that keys a tool now names the renamed class', function (): void {
    $this->migration->up();

    foreach ([
        'tool_configurations',
        'agent_tools',
        'agent_tool_overrides',
        'agent_tool_operation_overrides',
        'tool_user_settings',
    ] as $table) {
        expect((int) Capsule::table($table)->where('tool_class', 'Spora\\Tools\\UserInfoTool')->count())->toBe(0)
            ->and((int) Capsule::table($table)->where('tool_class', 'Spora\\Tools\\PrincipalInfoTool')->count())->toBe(1);
    }
});

test('the tables that carry the wire name are renamed too', function (): void {
    $this->migration->up();

    foreach (['tool_configurations', 'agent_tools'] as $table) {
        expect((int) Capsule::table($table)->where('tool_name', 'user_info')->count())->toBe(0)
            ->and((int) Capsule::table($table)->where('tool_name', 'principal_info')->count())->toBe(1);
    }
});

test('the audit trail keeps the class that actually ran', function (): void {
    $this->migration->up();

    $call = Capsule::table('tool_calls')->first();

    expect((string) $call->tool_class)->toBe('Spora\\Tools\\UserInfoTool')
        ->and((string) $call->tool_name)->toBe('user_info');
});

test('another tool is left alone', function (): void {
    Capsule::table('tool_configurations')->insert([
        'tool_class' => 'Spora\\Tools\\TimeTool',
        'tool_name'  => 'time',
        'settings'   => '{}',
    ]);

    $this->migration->up();

    expect((int) Capsule::table('tool_configurations')->where('tool_class', 'Spora\\Tools\\TimeTool')->count())->toBe(1);
});

test('down puts the old class and name back', function (): void {
    $this->migration->up();
    $this->migration->down();

    foreach ([
        'tool_configurations',
        'agent_tools',
        'agent_tool_overrides',
        'agent_tool_operation_overrides',
        'tool_user_settings',
    ] as $table) {
        expect((int) Capsule::table($table)->where('tool_class', 'Spora\\Tools\\UserInfoTool')->count())->toBe(1)
            ->and((int) Capsule::table($table)->where('tool_class', 'Spora\\Tools\\PrincipalInfoTool')->count())->toBe(0);
    }

    foreach (['tool_configurations', 'agent_tools'] as $table) {
        expect((int) Capsule::table($table)->where('tool_name', 'user_info')->count())->toBe(1)
            ->and((int) Capsule::table($table)->where('tool_name', 'principal_info')->count())->toBe(0);
    }
});
