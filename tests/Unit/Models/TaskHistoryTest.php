<?php

declare(strict_types=1);

use Spora\Models\Agent;

const TASK_HISTORY_TEST_PASSWORD = 'Password1!';
use Spora\Models\Task;
use Spora\Models\TaskHistory;

it('uses the task_history table', function (): void {
    $history = new TaskHistory();

    expect($history->getTable())->toBe('task_history');
});

it('disables updated_at (append-only)', function (): void {
    expect((new TaskHistory())->getUpdatedAtColumn())->toBeNull();
});

it('allows mass assignment of history fields', function (): void {
    $userId = bootAuthLayer()->register('history@example.com', TASK_HISTORY_TEST_PASSWORD, 'History');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'History Agent',
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

    $history = TaskHistory::create([
        'task_id'     => $task->id,
        'sequence'    => 0,
        'role'        => 'user',
        'content'     => 'hello',
    ]);

    expect($history->task_id)->toBe($task->id)
        ->and($history->sequence)->toBe(0)
        ->and($history->role)->toBe('user')
        ->and($history->content)->toBe('hello');
});

it('belongs to a task', function (): void {
    $userId = bootAuthLayer()->register('history2@example.com', TASK_HISTORY_TEST_PASSWORD, 'History 2');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'History Agent 2',
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
    $history = TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 0,
        'role'     => 'assistant',
        'content'  => 'reply',
    ]);

    expect($history->task)->toBeInstanceOf(Task::class)
        ->and((int) $history->task->getKey())->toBe($task->id);
});
