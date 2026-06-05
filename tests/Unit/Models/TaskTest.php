<?php

declare(strict_types=1);

use Spora\Models\Agent;

const TASK_TEST_PASSWORD = 'Password1!';
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Spora\Models\User;

it('uses the tasks table', function (): void {
    $task = new Task();

    expect($task->getTable())->toBe('tasks');
});

it('casts step_count, max_steps, retry_count to integers and data to array', function (): void {
    $userId = bootAuthLayer()->register('task-cast@example.com', TASK_TEST_PASSWORD, 'Task');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Task Agent',
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
        'data'        => ['key' => 'value'],
    ]);

    expect($task->step_count)->toBeInt()
        ->and($task->max_steps)->toBeInt()
        ->and($task->data)->toBe(['key' => 'value']);
});

it('belongs to an agent and a user', function (): void {
    $userId = bootAuthLayer()->register('task-belong@example.com', TASK_TEST_PASSWORD, 'Task');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Belongs Agent',
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

    expect($task->agent)->toBeInstanceOf(Agent::class)
        ->and((int) $task->agent->getKey())->toBe($agent->id)
        ->and($task->user)->toBeInstanceOf(User::class)
        ->and((int) $task->user->getKey())->toBe($userId);
});

it('has many task history entries and tool calls', function (): void {
    $userId = bootAuthLayer()->register('task-hasmany@example.com', TASK_TEST_PASSWORD, 'TaskMany');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Many Agent',
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

    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'hi']);
    ToolCall::create([
        'task_id'             => $task->id,
        'agent_id'            => $agent->id,
        'provider_call_id'    => 'p1',
        'tool_name'           => 'stub_output',
        'tool_class'          => 'StubOutputTool',
        'tool_type'           => 'function',
        'operation'           => 'echo',
        'operation_description' => 'Echo',
        'status'              => 'PENDING',
        'proposed_arguments'  => [],
    ]);

    expect($task->taskHistory)->toHaveCount(1)
        ->and($task->toolCalls)->toHaveCount(1);
});
