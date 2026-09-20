<?php

declare(strict_types=1);

use Spora\Todo\TodoStoreRegistry;
use Spora\Tools\TodoTool;

function todoTool(TodoStoreRegistry $registry = new TodoStoreRegistry()): TodoTool
{
    return new TodoTool($registry);
}

it('rejects when todos argument is missing', function (): void {
    $result = todoTool()->execute([], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("'todos' is missing");
});

it('rejects when todos is not an array', function (): void {
    $result = todoTool()->execute(['todos' => 'not-an-array'], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('must be an array');
});

it('clears the list when an empty array is supplied', function (): void {
    $result = todoTool()->execute(['todos' => []], 1, null, 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('cleared')
        ->and($result->data['items'])->toBe([]);
});

it('persists a single todo with default status pending', function (): void {
    $taskId = createTodoTestTask();
    $result = todoTool()->execute([
        'todos' => [
            ['content' => 'Write the README'],
        ],
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue();
    $items = $result->data['items'];
    expect($items)->toHaveCount(1)
        ->and($items[0]['content'])->toBe('Write the README')
        ->and($items[0]['status'])->toBe('pending')
        ->and($items[0]['order'])->toBe(0)
        ->and($items[0]['id'])->not->toBeNull();
});

it('rejects todo items missing content', function (): void {
    $result = todoTool()->execute([
        'todos' => [
            ['status' => 'pending'],
        ],
    ], 1, null, 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("non-empty 'content'");
});

it('rejects todo items with unknown status', function (): void {
    $result = todoTool()->execute([
        'todos' => [
            ['content' => 'do thing', 'status' => 'bogus'],
        ],
    ], 1, null, 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("status must be one of");
});

it('accepts multiple items and surfaces a warning when more than one is in_progress', function (): void {
    $taskId = createTodoTestTask();
    $result = todoTool()->execute([
        'todos' => [
            ['content' => 'task A', 'status' => 'in_progress'],
            ['content' => 'task B', 'status' => 'in_progress'],
        ],
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('**Note:** 2 items are marked `in_progress` at once');
});

it('caps content and activeForm lengths with an ellipsis', function (): void {
    $taskId = createTodoTestTask();
    $longContent = str_repeat('a', 600);
    $longActiveForm = str_repeat('b', 250);

    $result = todoTool()->execute([
        'todos' => [
            ['content' => $longContent, 'activeForm' => $longActiveForm, 'status' => 'pending'],
        ],
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue();
    $items = $result->data['items'];
    expect(mb_strlen($items[0]['content'], 'UTF-8'))->toBe(500)
        ->and($items[0]['content'])->toEndWith('…')
        ->and(mb_strlen($items[0]['activeForm'], 'UTF-8'))->toBe(200)
        ->and($items[0]['activeForm'])->toEndWith('…');
});

it('renders the new list back in the tool result content', function (): void {
    $taskId = createTodoTestTask();
    $result = todoTool()->execute([
        'todos' => [
            ['content' => 'Open the file', 'status' => 'in_progress', 'activeForm' => 'Opening the file'],
            ['content' => 'Read the file', 'status' => 'pending'],
            ['content' => 'Save the file', 'status' => 'completed'],
        ],
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('- [~] Open the file')
        ->and($result->content)->toContain('_(Opening the file)_')
        ->and($result->content)->toContain('- [ ] Read the file')
        ->and($result->content)->toContain('- [x] Save the file');
});

it('emits a single-operation schema with the write op', function (): void {
    $schema = todoTool()->getParametersSchema();
    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('todos')
        ->and($schema['properties']['todos']['type'])->toBe('array');
});

function createTodoTestTask(): int
{
    $userId = bootAuthLayer()->register('todo-tool@example.com', 'Password1!', 'Todo');
    simulateLoggedInSession($userId, 'todo-tool@example.com');

    $agent = Spora\Models\Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Todo Agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    return Spora\Models\Task::create([
        'agent_id'       => $agent->id,
        'principal_id'   => $agent->principal_id,
        'trigger_user_id' => $userId,
        'status'         => 'RUNNING',
        'user_prompt'    => 'p',
        'step_count'     => 0,
        'max_steps'      => 10,
    ])->id;
}
