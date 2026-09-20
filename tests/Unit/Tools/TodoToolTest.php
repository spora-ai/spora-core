<?php

declare(strict_types=1);

use Spora\Todo\TodoStoreRegistry;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\TodoTool;

function todoTool(TodoStoreRegistry $registry = new TodoStoreRegistry()): TodoTool
{
    return new TodoTool($registry);
}

function todoToolDescription(): string
{
    $attrs = (new ReflectionClass(TodoTool::class))->getAttributes(Tool::class);
    return $attrs[0]->newInstance()->description;
}

it('rejects when todos argument is missing', function (): void {
    $result = todoTool()->execute([], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("For op=write, 'todos' is required");
});

it('rejects when todos is not an array', function (): void {
    $result = todoTool()->execute(['todos' => 'not-an-array'], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('must be an array')
        ->and($result->content)->toContain('op=write');
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
        ->and($result->content)->toContain("'status' must be one of")
        ->and($result->content)->toContain('pending, in_progress, completed');
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

it('emits a schema covering all four ops via the synthesized op enum', function (): void {
    $schema = todoTool()->getParametersSchema();
    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('op')
        ->and($schema['properties']['op']['enum'])->toBe(['write', 'add', 'set_status', 'read'])
        ->and($schema['required'])->toContain('op');
});

it('narrows required[] to todos on op=write, item on op=add, id+status on op=set_status', function (): void {
    $schema = todoTool()->getParametersSchema();

    // Single-op filter strips the discriminator from required[] (back-compat).
    $write = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['write'], 'op');
    expect($write['required'])->toContain('todos')
        ->and($write['required'])->not->toContain('item', 'id', 'status');

    $add = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['add'], 'op');
    expect($add['required'])->toContain('item')
        ->and($add['required'])->not->toContain('todos', 'id', 'status');

    $setStatus = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['set_status'], 'op');
    expect($setStatus['required'])->toContain('id', 'status')
        ->and($setStatus['required'])->not->toContain('todos', 'item');

    $read = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['read'], 'op');
    expect($read['required'])->toBe([])
        ->and($read['properties']['op']['enum'])->toBe(['read']);

    // Multi-op filter keeps the discriminator (must be selected at call time).
    $threeOps = Spora\Tools\Schema\OperationSchemaFilter::filter(
        $schema,
        ['write', 'add', 'set_status'],
        'op',
    );
    expect($threeOps['required'])->toContain('op');
});

it('exposes the status enum on op=set_status', function (): void {
    $schema = todoTool()->getParametersSchema();
    expect($schema['properties']['status']['enum'])->toBe(['pending', 'in_progress', 'completed']);
});

it('op=add appends a single item to the current list', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [['content' => 'Existing', 'status' => 'pending']],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Brand new item', 'status' => 'in_progress'],
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->data['op'])->toBe('add')
        ->and($result->data['items'])->toHaveCount(2)
        ->and($result->data['items'][0]['content'])->toBe('Existing')
        ->and($result->data['items'][1]['content'])->toBe('Brand new item')
        ->and($result->data['items'][1]['order'])->toBe(1)
        ->and($result->content)->toContain('- [~] Brand new item');
});

it('op=add auto-generates an id slug from content when id is absent', function (): void {
    $taskId = createTodoTestTask();

    $result = todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Run the migration'],
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'][0]['id'])->toBe('run-the-migration');
});

it('op=add suffixes slug on collision (run-the-migration, run-the-migration-2)', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Run the migration'],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Run the migration'],
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'][0]['id'])->toBe('run-the-migration')
        ->and($result->data['items'][1]['id'])->toBe('run-the-migration-2');
});

it('op=add rejects a duplicate id with a clear validation error', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Existing', 'id' => 'run-migration'],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Clash', 'id' => 'run-migration'],
    ], 1, null, $taskId);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("'run-migration' is already in the list")
        ->and($result->content)->toContain('op=set_status');
});

it('op=add rejects missing item or empty content', function (): void {
    $taskId = createTodoTestTask();

    expect(todoTool()->execute(['op' => 'add'], 1, null, $taskId)->success)->toBeFalse();
    expect(todoTool()->execute(['op' => 'add', 'item' => 'not-object'], 1, null, $taskId)->success)->toBeFalse();
    expect(todoTool()->execute(['op' => 'add', 'item' => ['status' => 'pending']], 1, null, $taskId)->success)->toBeFalse();
    expect(todoTool()->execute(['op' => 'add', 'item' => ['content' => '', 'status' => 'pending']], 1, null, $taskId)->success)->toBeFalse();
});

it('op=add warns when the resulting list has more than one in_progress', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'   => 'write',
        'todos' => [['content' => 'Already in progress', 'status' => 'in_progress']],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Second in progress', 'status' => 'in_progress'],
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('**Note:** 2 items are marked `in_progress` at once');
});

it('op=set_status flips one item by id and preserves order', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'A', 'status' => 'pending', 'id' => 'a'],
            ['content' => 'B', 'status' => 'pending', 'id' => 'b'],
            ['content' => 'C', 'status' => 'pending', 'id' => 'c'],
        ],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'b',
        'status' => 'in_progress',
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->data['op'])->toBe('set_status')
        ->and(array_column($result->data['items'], 'id'))->toBe(['a', 'b', 'c'])
        ->and($result->data['items'][1]['status'])->toBe('in_progress')
        ->and($result->content)->toContain('- [~] B');
});

it('op=set_status is idempotent — re-marking completed is no-op success', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [['content' => 'Done', 'status' => 'completed', 'id' => 'done-1']],
    ], 1, null, $taskId);

    $first = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'done-1',
        'status' => 'completed',
    ], 1, null, $taskId);

    expect($first->success)->toBeTrue()
        ->and($first->content)->not->toContain('**Note:**');
});

it('op=set_status rejects an unknown id', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [['content' => 'A', 'status' => 'pending', 'id' => 'a']],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'ghost',
        'status' => 'completed',
    ], 1, null, $taskId);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("'ghost' not found");
});

it('op=set_status rejects an unknown status value', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [['content' => 'A', 'status' => 'pending', 'id' => 'a']],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'a',
        'status' => 'bogus',
    ], 1, null, $taskId);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("must be one of: pending, in_progress, completed");
});

it('op=set_status rejects when applying would leave more than one in_progress', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'A', 'status' => 'in_progress', 'id' => 'a'],
            ['content' => 'B', 'status' => 'pending',     'id' => 'b'],
        ],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'b',
        'status' => 'in_progress',
    ], 1, null, $taskId);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Cannot mark 'b' as 'in_progress'")
        ->and($result->content)->toContain('would leave 2 items in_progress')
        ->and($result->content)->toContain('Maintain exactly one')
        ->and($result->content)->toContain('[a, b]');
});

it('op=set_status rejects with all offending ids when three items would conflict', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'A', 'status' => 'in_progress', 'id' => 'a'],
            ['content' => 'B', 'status' => 'pending',     'id' => 'b'],
            ['content' => 'C', 'status' => 'pending',     'id' => 'c'],
        ],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'c',
        'status' => 'in_progress',
    ], 1, null, $taskId);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('would leave 2 items in_progress')
        ->and($result->content)->toContain('[a, c]');
});

it('op=set_status still completes an in_progress item while another is in_progress (no in_progress added)', function (): void {
    // Sanity-check that flipping TO pending or completed is allowed
    // even when other items are in_progress — the guard only fires when
    // the new status would create a second in_progress.
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'A', 'status' => 'in_progress', 'id' => 'a'],
            ['content' => 'B', 'status' => 'pending',     'id' => 'b'],
        ],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'b',
        'status' => 'completed',
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->not->toContain('Cannot mark');
});

it('op=set_status to in_progress succeeds when no other item is currently in_progress', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'A', 'status' => 'completed', 'id' => 'a'],
            ['content' => 'B', 'status' => 'pending',   'id' => 'b'],
        ],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'b',
        'status' => 'in_progress',
    ], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('- [~] B')
        ->and($result->content)->not->toContain('Cannot mark');
});

it('op=set_status names the missing id and the offending op in the error', function (): void {
    $result = todoTool()->execute(['op' => 'set_status', 'status' => 'completed'], 1, null, 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("For op=set_status, 'id' is required")
        ->and($result->content)->toContain('top-level');
});

it('op=set_status names the missing status in the error', function (): void {
    $result = todoTool()->execute(['op' => 'set_status', 'id' => 'a'], 1, null, 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("For op=set_status, 'status' is required")
        ->and($result->content)->toContain('pending, in_progress, completed');
});

it('op=add names the missing item field in the error', function (): void {
    $result = todoTool()->execute(['op' => 'add'], 1, null, 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("For op=add, 'item' is required")
        ->and($result->content)->toContain("'content'");
});

it('op=write names the missing todos field in the error', function (): void {
    $result = todoTool()->execute(['op' => 'write'], 1, null, 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("For op=write, 'todos' is required");
});

it('op=add duplicate-id error mentions op=set_status as the recovery path', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [['content' => 'Existing', 'status' => 'pending', 'id' => 'shared']],
    ], 1, null, $taskId);

    $result = todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Clash', 'id' => 'shared'],
    ], 1, null, $taskId);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("For op=add, 'item.id' 'shared' is already in the list")
        ->and($result->content)->toContain('op=set_status');
});

it('description documents all four ops and their parameter sets', function (): void {
    $description = todoToolDescription();

    expect($description)
        ->toContain('op=write:')
        ->toContain('{op, todos}')
        ->toContain('op=add:')
        ->toContain('{op, item}')
        ->toContain('op=set_status:')
        ->toContain('{op, id, status}')
        ->toContain('op=read:')
        ->toContain('{op}');
});

it('description includes worked examples for all four ops', function (): void {
    $description = todoToolDescription();

    // The <examples> block must carry a copy-paste-ready payload per op;
    // if a future edit accidentally drops one, this catches it before the
    // LLM sees a schema-only prompt and burns a turn guessing shapes.
    expect($description)
        ->toContain('<examples>')
        ->toContain('"op": "write"')
        ->toContain('"op": "add"')
        ->toContain('"op": "set_status"')
        ->toContain('"op": "read"');
});

it('description clarifies that set_status id is top-level, not nested in a wrapper', function (): void {
    $description = todoToolDescription();

    expect($description)
        ->toContain('top-level')
        ->toContain('not inside any wrapper object');
});

it('description clarifies that item is op=add only', function (): void {
    $description = todoToolDescription();

    expect($description)
        ->toContain('item')
        ->toContain('ONLY field')
        ->toContain('op=add');
});

it('op=read returns the current state without mutation', function (): void {
    $taskId = createTodoTestTask();

    todoTool()->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'A', 'status' => 'pending', 'id' => 'a'],
            ['content' => 'B', 'status' => 'in_progress', 'id' => 'b'],
        ],
    ], 1, null, $taskId);

    $result = todoTool()->execute(['op' => 'read'], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->data['op'])->toBe('read')
        ->and($result->data['items'])->toHaveCount(2)
        ->and($result->content)->toContain('- [ ] A')
        ->and($result->content)->toContain('- [~] B');

    $second = todoTool()->execute(['op' => 'read'], 1, null, $taskId);
    expect($second->data['items'])->toBe($result->data['items']);
});

it('op=read on an empty list returns the empty-state payload', function (): void {
    $taskId = createTodoTestTask();

    $result = todoTool()->execute(['op' => 'read'], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe('Todo list is empty.')
        ->and($result->data['items'])->toBe([]);
});

it('echo invariant — every op returns the full new state in content and data.items', function (): void {
    $taskId = createTodoTestTask();

    $write = todoTool()->execute([
        'op'    => 'write',
        'todos' => [
            ['content' => 'Run the migration', 'status' => 'in_progress', 'activeForm' => 'Running the migration', 'id' => 'run-migration'],
            ['content' => 'Update the API client', 'status' => 'pending', 'id' => 'update-api'],
            ['content' => 'Send notification', 'status' => 'completed', 'id' => 'send-notification'],
        ],
    ], 1, null, $taskId);
    expect($write->success)->toBeTrue();
    expect($write->data['items'])->toHaveCount(3);
    expect($write->content)->toContain('- [~] Run the migration')
        ->and($write->content)->toContain('_(Running the migration)_')
        ->and($write->content)->toContain('- [ ] Update the API client')
        ->and($write->content)->toContain('- [x] Send notification');
    expect(array_column($write->data['items'], 'id'))->toBe(['run-migration', 'update-api', 'send-notification']);
    expect(array_column($write->data['items'], 'content'))->toBe([
        'Run the migration',
        'Update the API client',
        'Send notification',
    ]);

    $add = todoTool()->execute([
        'op'   => 'add',
        'item' => ['content' => 'Roll back if needed', 'status' => 'pending'],
    ], 1, null, $taskId);
    expect($add->data['items'])->toHaveCount(4);
    expect($add->content)->toContain('- [ ] Roll back if needed');
    $fourthId = $add->data['items'][3]['id'];
    expect(array_column($add->data['items'], 'id'))->toContain('run-migration', 'update-api', 'send-notification', $fourthId);

    // Complete run-migration first so the next set_status can move
    // update-api to in_progress without violating the one-in-progress
    // invariant (which the guard now hard-rejects).
    $complete = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'run-migration',
        'status' => 'completed',
    ], 1, null, $taskId);
    expect($complete->data['items'])->toHaveCount(4);
    expect(array_column($complete->data['items'], 'status'))->toBe([
        'completed', 'pending', 'completed', 'pending',
    ]);

    $set = todoTool()->execute([
        'op'     => 'set_status',
        'id'     => 'update-api',
        'status' => 'in_progress',
    ], 1, null, $taskId);
    expect($set->data['items'])->toHaveCount(4);
    expect(array_column($set->data['items'], 'status'))->toBe([
        'completed', 'in_progress', 'completed', 'pending',
    ]);
    expect($set->content)->not->toContain('**Note:**');

    $read = todoTool()->execute(['op' => 'read'], 1, null, $taskId);
    expect($read->data['items'])->toBe($set->data['items']);
});

it('returns the empty-state payload for op=write with empty todos', function (): void {
    $taskId = createTodoTestTask();
    todoTool()->execute(['op' => 'write', 'todos' => [['content' => 'A', 'id' => 'a']]], 1, null, $taskId);

    $result = todoTool()->execute(['op' => 'write', 'todos' => []], 1, null, $taskId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe('Todo list cleared.')
        ->and($result->data['items'])->toBe([]);
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
