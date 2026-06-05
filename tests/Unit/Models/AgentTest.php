<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\ToolCall;
use Spora\Models\User;

const AGENT_TEST_PASSWORD = 'Password1!';

it('uses the agents table', function (): void {
    $agent = new Agent();

    expect($agent->getTable())->toBe('agents');
});

it('casts boolean and integer fields', function (): void {
    $userId = bootAuthLayer()->register('agent-cast@example.com', AGENT_TEST_PASSWORD, 'Agent');

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Cast Agent',
        'llm_provider'         => 'mock',
        'llm_model'            => 'mock',
        'max_steps'            => 7,
        'is_active'            => true,
        'allow_followup'       => true,
    ]);

    expect($agent->is_active)->toBeBool()
        ->and($agent->getAttribute('allow_followup'))->toBeBool()
        ->and($agent->max_steps)->toBeInt();
});

it('belongs to a user', function (): void {
    $userId = bootAuthLayer()->register('agent-user@example.com', AGENT_TEST_PASSWORD, 'AgentUser');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Owner Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    expect($agent->user)->toBeInstanceOf(User::class)
        ->and((int) $agent->user->getKey())->toBe($userId);
});

it('has many tasks, agent tools, and tool calls', function (): void {
    $userId = bootAuthLayer()->register('agent-hasmany@example.com', AGENT_TEST_PASSWORD, 'HasMany');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'HasMany Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'hi',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);
    AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Spora\Tools\StubOutputTool',
        'tool_name'  => 'stub_output',
    ]);
    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'hi',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);
    ToolCall::create([
        'task_id'             => $task->id,
        'agent_id'            => $agent->id,
        'provider_call_id'    => 'orphan_call',
        'tool_name'           => 'stub_output',
        'tool_class'          => 'StubOutputTool',
        'tool_type'           => 'function',
        'operation'           => 'echo',
        'operation_description' => 'Echo',
        'status'              => 'PENDING',
        'proposed_arguments'  => [],
    ]);

    expect($agent->tasks)->toHaveCount(2)
        ->and($agent->agentTools)->toHaveCount(1)
        ->and($agent->toolCalls)->toHaveCount(1);
});
