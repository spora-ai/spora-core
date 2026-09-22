<?php

declare(strict_types=1);

use Spora\Agents\Exceptions\ToolCallFieldOverflowException;
use Spora\Agents\ToolCallInsertGuard;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\ToolCall;

beforeEach(function (): void {
    TestDatabaseFactory::freshDatabase();
    ToolCallInsertGuard::resetCache();
});

it('passes when every bounded field fits its column', function (): void {
    expect(fn() => ToolCallInsertGuard::assertInsertable([
        'tool_name'        => 'search',
        'tool_class'       => 'Spora\Tools\SearchTool',
        'tool_type'        => 'input',
        'status'           => 'PENDING_APPROVAL',
        'operation'        => 'default',
        'approval_note'    => 'ok',
    ], 'Spora\Tools\SearchTool'))->not()->toThrow(Throwable::class);
});

it('throws ToolCallFieldOverflowException when a VARCHAR field exceeds its cap', function (): void {
    // `tool_name` is VARCHAR(100) on `tool_calls`.
    try {
        ToolCallInsertGuard::assertInsertable([
            'tool_name' => str_repeat('x', 101),
        ], 'Spora\Tools\SearchTool');
        $this->fail('Expected ToolCallFieldOverflowException was not thrown.');
    } catch (ToolCallFieldOverflowException $e) {
        expect($e->field)->toBe('tool_name')
            ->and($e->actualLength)->toBe(101)
            ->and($e->maxLength)->toBe(100)
            ->and($e->toolClass)->toBe('Spora\Tools\SearchTool')
            ->and($e->getMessage())->toContain('tool_calls.tool_name')
            ->and($e->getMessage())->toContain('101 chars')
            ->and($e->getMessage())->toContain('column limit is 100');
    }
});

it('accepts arbitrarily long values on TEXT-family columns', function (): void {
    // `human_description` is MEDIUMTEXT on production, TEXT on SQLite — both
    // are unbounded for our purposes.
    expect(fn() => ToolCallInsertGuard::assertInsertable([
        'human_description' => str_repeat('a', 1_000_000),
    ], 'Spora\Tools\WhateverTool'))->not()->toThrow(Throwable::class);
});

it('ignores columns it does not know about (forward compatibility)', function (): void {
    // A future migration adding `tool_calls.extra_field VARCHAR(50)` must
    // not regress older callers that don't know about it. The guard is
    // best-effort: it only protects columns it sees in the live schema.
    expect(fn() => ToolCallInsertGuard::assertInsertable([
        'unknown_future_field' => str_repeat('x', 10_000),
    ], 'Spora\Tools\WhateverTool'))->not()->toThrow(Throwable::class);
});

it('counts multi-byte characters using mb_strlen so it matches utf8mb4 VARCHAR semantics', function (): void {
    // 100 emoji = 100 chars under mb_strlen, fits VARCHAR(100). If the guard
    // had used strlen() it would see 400 bytes and falsely reject.
    expect(fn() => ToolCallInsertGuard::assertInsertable([
        'tool_name' => str_repeat('🚀', 100),
    ], 'Spora\Tools\SearchTool'))->not()->toThrow(Throwable::class);
});

it('treats null and non-string values as inert (no length check)', function (): void {
    expect(fn() => ToolCallInsertGuard::assertInsertable([
        'tool_name'     => null,
        'tool_type'     => 12345,
        'status'        => ['not', 'a', 'string'],
        'approval_note' => null,
    ], 'Spora\Tools\WhateverTool'))->not()->toThrow(Throwable::class);
});

it('fires through the ToolCall Eloquent saving hook on insert', function (): void {
    $userId = bootAuthLayer()->register('guard-insert@example.com', 'Password1!', 'Guard');
    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Guard Agent',
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
        'user_prompt'     => 'guard test',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    // tool_name is VARCHAR(100). 101 chars must trip the saving listener
    // before the SQL INSERT ever runs.
    expect(fn() => ToolCall::create([
        'task_id'               => $task->id,
        'agent_id'              => $agent->id,
        'provider_call_id'      => 'call_guard',
        'tool_name'             => str_repeat('x', 101),
        'tool_class'            => 'Spora\Tools\WhateverTool',
        'tool_type'             => 'input',
        'status'                => 'PENDING_APPROVAL',
        'proposed_arguments'    => [],
    ]))->toThrow(ToolCallFieldOverflowException::class);
});

it('fires through the ToolCall Eloquent saving hook on update', function (): void {
    $userId = bootAuthLayer()->register('guard-update@example.com', 'Password1!', 'Guard');
    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'         => 'Guard Agent 2',
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
        'user_prompt'     => 'guard test 2',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    $call = ToolCall::create([
        'task_id'            => $task->id,
        'agent_id'           => $agent->id,
        'provider_call_id'   => 'call_guard_2',
        'tool_name'          => 'short_name',
        'tool_class'         => 'Spora\Tools\WhateverTool',
        'tool_type'          => 'input',
        'status'             => 'PENDING_APPROVAL',
        'proposed_arguments' => [],
    ]);

    // approval_note is VARCHAR(500) — 501 chars must trip the listener on
    // the UPDATE path.
    expect(fn() => $call->update(['approval_note' => str_repeat('y', 501)]))
        ->toThrow(ToolCallFieldOverflowException::class);
});
