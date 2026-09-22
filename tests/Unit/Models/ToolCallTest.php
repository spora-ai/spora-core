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
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'ToolCall Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'agent_id'    => $agent->id,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
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
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Cast TC Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'agent_id'    => $agent->id,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
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
        'rejected_at'         => '2025-01-01 11:00:00',
        'rejected_by'         => $userId,
        'reject_reason'       => 'Rejected in test',
        'executed_at'         => '2025-01-01 12:00:00',
    ]);

    expect($call->proposed_arguments)->toBeArray()
        ->and($call->approved_arguments)->toBeArray()
        ->and($call->result_data)->toBeArray()
        ->and($call->rejected_at)->toBeInstanceOf(Carbon\Carbon::class)
        ->and($call->rejected_by)->toBeInt()
        ->and($call->reject_reason)->toBeString()
        ->and($call->executed_at)->toBeInstanceOf(Carbon\Carbon::class);
});

it('belongs to a task, agent, and approver', function (): void {
    $userId = bootAuthLayer()->register('rel-tc@example.com', TOOL_CALL_TEST_PASSWORD, 'Rel');
    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Rel Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'agent_id'    => $agent->id,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
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
        'rejected_by'         => $userId,
    ]);

    expect($call->task)->toBeInstanceOf(Task::class)
        ->and($call->agent)->toBeInstanceOf(Agent::class)
        ->and($call->approvedBy)->toBeInstanceOf(User::class)
        ->and($call->rejectedBy)->toBeInstanceOf(User::class);
});

it('assertStringColumnsFit throws InvalidArgumentException when a VARCHAR field exceeds its cap', function (): void {
    $call = new ToolCall();
    $call->tool_class = 'Spora\Tools\SearchTool';
    // tool_name is VARCHAR(100) on `tool_calls` — 101 chars is the regression case.
    $call->tool_name = str_repeat('x', 101);

    try {
        $call->assertStringColumnsFit();
        $this->fail('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('tool_calls.tool_name')
            ->and($e->getMessage())->toContain('101 chars')
            ->and($e->getMessage())->toContain('column limit is 100')
            ->and($e->getMessage())->toContain('Spora\\Tools\\SearchTool');
    }
});

it('assertStringColumnsFit counts multi-byte characters under mb_strlen to match utf8mb4 VARCHAR semantics', function (): void {
    // 100 emoji = 100 chars under mb_strlen, fits VARCHAR(100). strlen() would
    // see 400 bytes and falsely reject.
    $call = new ToolCall();
    $call->tool_name = str_repeat('🚀', 100);

    expect(fn() => $call->assertStringColumnsFit())->not()->toThrow(InvalidArgumentException::class);
});

it('save() rejects a row whose tool_name exceeds VARCHAR(100)', function (): void {
    $userId = bootAuthLayer()->register('save-insert@example.com', TOOL_CALL_TEST_PASSWORD, 'Save');
    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Save Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'save test',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    expect(fn() => ToolCall::create([
        'task_id'            => $task->id,
        'agent_id'           => $agent->id,
        'provider_call_id'   => 'call_save_1',
        'tool_name'          => str_repeat('x', 101),
        'tool_class'         => 'Spora\Tools\WhateverTool',
        'tool_type'          => 'input',
        'status'             => 'PENDING_APPROVAL',
        'proposed_arguments' => [],
    ]))->toThrow(InvalidArgumentException::class);
});

it('save() rejects an update whose approval_note exceeds VARCHAR(500)', function (): void {
    $userId = bootAuthLayer()->register('save-update@example.com', TOOL_CALL_TEST_PASSWORD, 'Save');
    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Save Agent 2',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'save test 2',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    $call = ToolCall::create([
        'task_id'            => $task->id,
        'agent_id'           => $agent->id,
        'provider_call_id'   => 'call_save_2',
        'tool_name'          => 'short_name',
        'tool_class'         => 'Spora\Tools\WhateverTool',
        'tool_type'          => 'input',
        'status'             => 'PENDING_APPROVAL',
        'proposed_arguments' => [],
    ]);

    expect(fn() => $call->update(['approval_note' => str_repeat('y', 501)]))
        ->toThrow(InvalidArgumentException::class);
});
