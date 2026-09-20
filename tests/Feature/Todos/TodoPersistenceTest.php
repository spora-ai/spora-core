<?php

declare(strict_types=1);

use Spora\Agents\MessageHistoryBuilder;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Todo\TodoItemStatus;
use Spora\Todo\TodoState;
use Spora\Todo\TodoStore;
use Spora\Todo\TodoStoreRegistry;
use Spora\Tools\TodoTool;

function newTodoTestTask(): Task
{
    $userId = bootAuthLayer()->register('todo-feature@example.com', 'Password1!', 'Todo Feature');
    simulateLoggedInSession($userId, 'todo-feature@example.com');

    $agent = Spora\Models\Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Todo Feature Agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    return Task::create([
        'agent_id'       => $agent->id,
        'principal_id'   => $agent->principal_id,
        'trigger_user_id' => $userId,
        'status'         => 'RUNNING',
        'user_prompt'    => 'p',
        'step_count'     => 0,
        'max_steps'      => 10,
    ]);
}

it('round-trips the todo list via TodoStore', function (): void {
    $task = newTodoTestTask();
    $store = new TodoStore($task->id);

    $state = new TodoState(
        version: 1,
        items: [
            new Spora\Todo\TodoItem(
                id: 't_abc',
                content: 'First',
                activeForm: 'Doing first',
                status: TodoItemStatus::InProgress,
                order: 0,
            ),
            new Spora\Todo\TodoItem(
                id: 't_def',
                content: 'Second',
                activeForm: null,
                status: TodoItemStatus::Pending,
                order: 1,
            ),
        ],
        updatedAt: Carbon\CarbonImmutable::now('UTC'),
    );

    $store->replace($state);

    $reread = $store->read();
    expect($reread->version)->toBe(1)
        ->and($reread->items)->toHaveCount(2)
        ->and($reread->items[0]->content)->toBe('First')
        ->and($reread->items[0]->status)->toBe(TodoItemStatus::InProgress)
        ->and($reread->items[1]->content)->toBe('Second')
        ->and($reread->items[1]->activeForm)->toBeNull()
        ->and($reread->getActiveItem()?->id)->toBe('t_abc');
});

it('TodoTool persists replace semantics', function (): void {
    $task = newTodoTestTask();
    $tool = new TodoTool(new TodoStoreRegistry());

    $tool->execute([
        'todos' => [
            ['content' => 'one', 'status' => 'completed'],
            ['content' => 'two', 'status' => 'in_progress'],
        ],
    ], 1, null, $task->id);

    $stored = (new TodoStore($task->id))->read();
    expect($stored->items)->toHaveCount(2)
        ->and($stored->items[0]->content)->toBe('one')
        ->and($stored->items[1]->content)->toBe('two');

    $tool->execute([
        'todos' => [
            ['content' => 'replaced', 'status' => 'pending'],
        ],
    ], 1, null, $task->id);

    $stored = (new TodoStore($task->id))->read();
    expect($stored->items)->toHaveCount(1)
        ->and($stored->items[0]->content)->toBe('replaced');
});

it('keeps task.data.todos untouched when MessageHistoryBuilder runs compaction', function (): void {
    $task = newTodoTestTask();
    $tool = new TodoTool(new TodoStoreRegistry());
    $tool->execute([
        'todos' => [
            ['content' => 'survive compaction', 'status' => 'pending'],
        ],
    ], 1, null, $task->id);

    $snapshot = (new TodoStore($task->id))->read();

    // The compaction pipeline trims the LLM-facing message list from
    // task_history rows; tasks.data lives on the tasks row itself and
    // must never be touched.
    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toBeArray();

    $after = (new TodoStore($task->id))->read();
    expect(count($after->items))->toBe(count($snapshot->items))
        ->and($after->items[0]->content)->toBe('survive compaction');

    $taskRow = Task::find($task->id);
    $data = $taskRow->data ?? [];
    expect($data['todos']['items'][0]['content'])->toBe('survive compaction');
});

it('survives a task_history row deletion (mirrors compaction)', function (): void {
    $task = newTodoTestTask();
    $tool = new TodoTool(new TodoStoreRegistry());
    $tool->execute([
        'todos' => [['content' => 'survive', 'status' => 'pending']],
    ], 1, null, $task->id);

    $rows = TaskHistory::where('task_id', $task->id)->get();
    foreach ($rows as $row) {
        $row->delete();
    }

    $stored = (new TodoStore($task->id))->read();
    expect($stored->items)->toHaveCount(1)
        ->and($stored->items[0]->content)->toBe('survive');
});

it('TodoTool persists op=add end-to-end', function (): void {
    $task = newTodoTestTask();
    $tool = new TodoTool(new TodoStoreRegistry());

    $tool->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'First',  'status' => 'pending', 'id' => 'first'],
        ],
    ], 1, null, $task->id);

    $tool->execute([
        'op'   => 'add',
        'item' => ['content' => 'Second', 'status' => 'in_progress'],
    ], 1, null, $task->id);

    $stored = (new TodoStore($task->id))->read();
    expect($stored->items)->toHaveCount(2)
        ->and($stored->items[0]->id)->toBe('first')
        ->and($stored->items[0]->content)->toBe('First')
        ->and($stored->items[1]->content)->toBe('Second')
        ->and($stored->items[1]->status)->toBe(TodoItemStatus::InProgress)
        ->and($stored->items[1]->order)->toBe(1);

    $taskRow = Task::find($task->id);
    expect($taskRow->data['todos']['items'])->toHaveCount(2)
        ->and($taskRow->data['todos']['items'][1]['content'])->toBe('Second');
});

it('TodoTool persists op=set_status end-to-end', function (): void {
    $task = newTodoTestTask();
    $tool = new TodoTool(new TodoStoreRegistry());

    $tool->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'One', 'status' => 'pending', 'id' => 'one'],
            ['content' => 'Two', 'status' => 'pending', 'id' => 'two'],
        ],
    ], 1, null, $task->id);

    $tool->execute([
        'op'     => 'set_status',
        'id'     => 'one',
        'status' => 'completed',
    ], 1, null, $task->id);

    $stored = (new TodoStore($task->id))->read();
    expect($stored->items)->toHaveCount(2)
        ->and($stored->items[0]->id)->toBe('one')
        ->and($stored->items[0]->status)->toBe(TodoItemStatus::Completed)
        ->and($stored->items[0]->order)->toBe(0)
        ->and($stored->items[1]->id)->toBe('two')
        ->and($stored->items[1]->status)->toBe(TodoItemStatus::Pending);

    $taskRow = Task::find($task->id);
    expect($taskRow->data['todos']['items'][0]['status'])->toBe('completed')
        ->and($taskRow->data['todos']['items'][1]['status'])->toBe('pending');
});

it('op=set_status on a missing id does not mutate the persisted state', function (): void {
    $task = newTodoTestTask();
    $tool = new TodoTool(new TodoStoreRegistry());

    $tool->execute([
        'op'    => 'write',
        'todos' => [['content' => 'Only', 'status' => 'pending', 'id' => 'only']],
    ], 1, null, $task->id);

    $before = (new TodoStore($task->id))->read();

    $result = $tool->execute([
        'op'     => 'set_status',
        'id'     => 'ghost',
        'status' => 'completed',
    ], 1, null, $task->id);

    expect($result->success)->toBeFalse();

    $after = (new TodoStore($task->id))->read();
    expect($after->items[0]->status)->toBe(TodoItemStatus::Pending)
        ->and($after->items[0]->id)->toBe('only')
        ->and($after->updatedAt?->toIso8601String())->toBe($before->updatedAt?->toIso8601String());
});
