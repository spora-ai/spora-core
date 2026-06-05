<?php

declare(strict_types=1);

use Spora\Models\Agent;

const TOOL_CALL_TEST_PASSWORD = 'Password1!';
use Spora\Models\Task;
use Spora\Models\ToolCall;
use Spora\Models\User;

it('uses the tool_calls table', function (): void {
    $call = new ToolCall();

    expect($call->getTable())->toBe('tool_calls');
});

it('allows mass assignment of tool call fields', function (): void {
    $userId = bootAuthLayer()->register('toolcall@example.com', TOOL_CALL_TEST_PASSWORD, 'ToolCall');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'ToolCall Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'hi',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $call = ToolCall::create([
        'task_id'             => $task->id,
        'agent_id'            => $agent->id,
        'provider_call_id'    => 'call_xyz',
        'tool_name'           => 'stub_output',
        'tool_class'          => 'StubOutputTool',
        'tool_type'           => 'function',
        'operation'           => 'echo',
        'operation_description' => 'Echo input',
        'status'              => 'PENDING_APPROVAL',
        'proposed_arguments'  => ['msg' => 'hi'],
    ]);

    expect($call->provider_call_id)->toBe('call_xyz')
        ->and($call->status)->toBe('PENDING_APPROVAL')
        ->and($call->proposed_arguments)->toBe(['msg' => 'hi']);
});

it('casts JSON columns to arrays and dates to Carbon', function (): void {
    $userId = bootAuthLayer()->register('cast-tc@example.com', TOOL_CALL_TEST_PASSWORD, 'Cast');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Cast TC Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'hi',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $call = ToolCall::create([
        'task_id'             => $task->id,
        'agent_id'            => $agent->id,
        'provider_call_id'    => 'call_zz',
        'tool_name'           => 'stub_output',
        'tool_class'          => 'StubOutputTool',
        'tool_type'           => 'function',
        'operation'           => 'echo',
        'operation_description' => 'Echo',
        'status'              => 'EXECUTED',
        'proposed_arguments'  => ['x' => 1],
        'approved_arguments'  => ['x' => 1],
        'result_data'         => ['ok' => true],
        'executed_at'         => '2025-01-01 12:00:00',
    ]);

    expect($call->proposed_arguments)->toBeArray()
        ->and($call->approved_arguments)->toBeArray()
        ->and($call->result_data)->toBeArray()
        ->and($call->executed_at)->toBeInstanceOf(Carbon\Carbon::class);
});

it('belongs to a task, agent, and approver', function (): void {
    $userId = bootAuthLayer()->register('rel-tc@example.com', TOOL_CALL_TEST_PASSWORD, 'Rel');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Rel Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'hi',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $call = ToolCall::create([
        'task_id'             => $task->id,
        'agent_id'            => $agent->id,
        'provider_call_id'    => 'call_rel',
        'tool_name'           => 'stub_output',
        'tool_class'          => 'StubOutputTool',
        'tool_type'           => 'function',
        'operation'           => 'echo',
        'operation_description' => 'Echo',
        'status'              => 'EXECUTED',
        'proposed_arguments'  => [],
        'approved_by'         => $userId,
    ]);

    expect($call->task)->toBeInstanceOf(Task::class)
        ->and($call->agent)->toBeInstanceOf(Agent::class)
        ->and($call->approvedBy)->toBeInstanceOf(User::class);
});
