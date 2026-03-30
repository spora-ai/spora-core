<?php

declare(strict_types=1);

use Spora\Agents\ValueObjects\AgentState;
use Spora\Drivers\ValueObjects\ToolCall;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeAgentState(array $overrides = []): AgentState
{
    return new AgentState(
        taskId: $overrides['taskId']          ?? 42,
        agentId: $overrides['agentId']         ?? 7,
        pendingToolCall: $overrides['pendingToolCall']  ?? new ToolCall(
            providerCallId: 'call_abc123',
            toolName: 'send_email',
            arguments: ['to' => 'user@example.com', 'subject' => 'Hello'],
        ),
        messageSnapshot: $overrides['messageSnapshot'] ?? [
            ['role' => 'user', 'content' => 'Send an email'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => []],
        ],
        stepCount: $overrides['stepCount']       ?? 3,
        maxSteps: $overrides['maxSteps']        ?? 10,
        pausedAt: $overrides['pausedAt']        ?? '2026-01-15T12:00:00Z',
    );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('fromJson(toJson()) roundtrip preserves all fields', function (): void {
    $original  = makeAgentState();
    $roundtrip = AgentState::fromJson($original->toJson());

    expect($roundtrip->taskId)->toBe($original->taskId);
    expect($roundtrip->agentId)->toBe($original->agentId);
    expect($roundtrip->stepCount)->toBe($original->stepCount);
    expect($roundtrip->maxSteps)->toBe($original->maxSteps);
    expect($roundtrip->pausedAt)->toBe($original->pausedAt);
    expect($roundtrip->messageSnapshot)->toBe($original->messageSnapshot);
    expect($roundtrip->pendingToolCall->providerCallId)->toBe($original->pendingToolCall->providerCallId);
    expect($roundtrip->pendingToolCall->toolName)->toBe($original->pendingToolCall->toolName);
    expect($roundtrip->pendingToolCall->arguments)->toBe($original->pendingToolCall->arguments);
});

test('serialized JSON uses step_count key (not stepCount or run_count)', function (): void {
    $state = makeAgentState(['stepCount' => 5]);
    $json  = $state->toJson();
    $data  = json_decode($json, true);

    expect($data)->toHaveKey('step_count');
    expect($data['step_count'])->toBe(5);
    expect($data)->not()->toHaveKey('stepCount');
    expect($data)->not()->toHaveKey('run_count');
});

test('serialized JSON uses snake_case keys for all fields', function (): void {
    $state = makeAgentState();
    $data  = json_decode($state->toJson(), true);

    expect($data)->toHaveKey('task_id');
    expect($data)->toHaveKey('agent_id');
    expect($data)->toHaveKey('pending_tool_call');
    expect($data)->toHaveKey('message_snapshot');
    expect($data)->toHaveKey('step_count');
    expect($data)->toHaveKey('max_steps');
    expect($data)->toHaveKey('paused_at');
});

test('pending_tool_call nested keys are snake_case', function (): void {
    $state = makeAgentState();
    $data  = json_decode($state->toJson(), true);

    expect($data['pending_tool_call'])->toHaveKey('provider_call_id');
    expect($data['pending_tool_call'])->toHaveKey('tool_name');
    expect($data['pending_tool_call'])->toHaveKey('arguments');
});

test('fromJson with empty message_snapshot uses empty array default', function (): void {
    $minimalJson = json_encode([
        'task_id'           => 1,
        'agent_id'          => 2,
        'pending_tool_call' => [
            'provider_call_id' => 'call_xyz',
            'tool_name'        => 'search_web',
            'arguments'        => [],
        ],
        // message_snapshot intentionally omitted to test default
        'step_count'        => 0,
        'max_steps'         => 10,
        'paused_at'         => '2026-01-01T00:00:00Z',
    ]);

    $state = AgentState::fromJson($minimalJson);

    expect($state->messageSnapshot)->toBe([]);
});
