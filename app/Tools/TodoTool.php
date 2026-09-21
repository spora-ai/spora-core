<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\PrincipalContext;
use Spora\Todo\TodoGuardException;
use Spora\Todo\TodoItem;
use Spora\Todo\TodoItemStatus;
use Spora\Todo\TodoState;
use Spora\Todo\TodoStoreRegistry;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The agent's working plan, persisted on `tasks.data.todos` and surfaced
 * to the operator UI. Mirrors Claude Code's `TodoWrite` semantics so any
 * model trained on it can be dropped into Spora without prompt engineering.
 *
 * Four operations on the same tool, selected by the `op` discriminator:
 *   - write       — full replace (pass empty array to clear).
 *   - add         — append one item; auto-generates the `id` slug when absent.
 *   - set_status  — flip one item's status by id.
 *   - read        — return the current state without mutating.
 *
 * Every op returns the full new state (the "echo invariant") in both the
 * rendered markdown `content` and the structured `data.items` array — the
 * model sees its own write, the UI keeps the structured payload.
 *
 * Lifecycle rules (also in `description`):
 *   - Use proactively for 3+ step tasks; skip trivial work.
 *   - Mark `in_progress` before starting; complete immediately after.
 *   - Maintain exactly one `in_progress` at a time.
 *   - Provide both forms: `content` (imperative) and `activeForm`
 *     (present-continuous). If blocked, leave the item `in_progress` and
 *     add a new item describing the blocker.
 */
#[Tool(
    name: 'todo',
    description: <<<'TEXT'
Manage a structured task list for the current work. Use proactively for multi-step tasks (3+ steps); skip trivial single-step requests, informational questions, or pure conversation.

Select the operation via `op`. Each op takes only the fields shown next to it — sending any other field is a validation error.
    op=write:        {op, todos}
    op=add:          {op, item}
    op=set_status:   {op, id, status}
    op=read:         {op}

For op=set_status, `id` is the item's id at the TOP LEVEL of the call (not inside any wrapper object). The id comes from earlier ops via `data.items[i].id`.
For op=add, `item` is the ONLY field that takes the new entry — a top-level object with at least `content`, and optionally `id`, `activeForm`, `status`.
Do NOT send `todos` for `set_status` or `read`; do NOT send `item` for `write`.

Lifecycle:
- Mark an item `in_progress` BEFORE you start work on it.
- Mark it `completed` immediately after — do not batch completions.
- Maintain exactly one `in_progress` item at any moment. `set_status` will REJECT a write that would leave more than one in_progress, so complete the current item before starting the next.
- If work is blocked, leave the item `in_progress` and add a new item describing the blocker; never mark an item complete unless its work is fully done.
- Each item carries both `content` (imperative, e.g. "Run the migration") and `activeForm` (present continuous, e.g. "Running the migration"). Use the same text in both when no live progress label is needed.
- Items that are no longer relevant should be removed (omit them from the next `write`); do not keep stale entries.

Every op returns the full new state — read `data.items[i].id` from the result to target the item on the next `set_status`.

<examples>
    {"op": "write", "todos": [{"content": "Run the migration", "status": "in_progress", "activeForm": "Running the migration", "id": "run-migration"}, {"content": "Update the API client", "status": "pending", "id": "update-api"}]}
    {"op": "add", "item": {"content": "Send notification", "id": "send-notification", "status": "pending"}}
    {"op": "set_status", "id": "run-migration", "status": "completed"}
    {"op": "read"}
TEXT,
    displayName: 'Task List',
    category: 'meta',
    icon: 'list-checks',
)]
#[ToolOperation(
    name: 'write',
    description: 'Replace the current list with the supplied one. Pass an empty array to clear it.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
    discriminatorKey: 'op',
)]
#[ToolOperation(
    name: 'add',
    description: 'Append a single item to the current list. `id` is optional and defaults to a slug derived from `content`.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
    discriminatorKey: 'op',
)]
#[ToolOperation(
    name: 'set_status',
    description: 'Update the status of one item by id. Order preserved; idempotent on the same status. Rejected when the resulting state would have more than one `in_progress` item.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
    discriminatorKey: 'op',
)]
#[ToolOperation(
    name: 'read',
    description: 'Return the current todo list without mutating it.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
    discriminatorKey: 'op',
)]
#[ToolParameter(
    name: 'todos',
    type: 'array',
    description: 'For op=write: the new todo list. Empty array clears the list.',
    required: ['write'],
)]
#[ToolParameter(
    name: 'item',
    type: 'object',
    description: 'For op=add: a single TodoItem to append. May include `id` for a stable handle; otherwise one is derived from `content`.',
    required: ['add'],
)]
#[ToolParameter(
    name: 'id',
    type: 'string',
    description: 'For op=set_status: the `id` of the item to update (top-level, returned in `data.items[i].id` from earlier ops).',
    required: ['set_status'],
)]
#[ToolParameter(
    name: 'status',
    type: 'string',
    description: 'For op=set_status: the new status.',
    required: ['set_status'],
    enum: ['pending', 'in_progress', 'completed'],
)]
final class TodoTool extends AbstractTool
{
    public function __construct(
        private readonly TodoStoreRegistry $registry = new TodoStoreRegistry(),
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $op = $this->getOperationName($arguments);

        return match ($op) {
            'write'      => $this->doWrite($arguments, $taskId),
            'add'        => $this->doAdd($arguments, $taskId),
            'set_status' => $this->doSetStatus($arguments, $taskId),
            'read'       => $this->doRead($taskId),
            default      => new ToolResult(false, "Unknown op '{$op}'. Use write, add, set_status, or read."),
        };
    }

    public function describeAction(array $arguments): string
    {
        $op = $this->getOperationName($arguments);

        return match ($op) {
            'write'      => $this->describeWrite($arguments),
            'add'        => 'Append a todo item',
            'set_status' => sprintf(
                "Mark todo '%s' as %s.",
                (string) ($arguments['id'] ?? '?'),
                (string) ($arguments['status'] ?? '?'),
            ),
            'read'       => 'Read the todo list',
            default      => 'Use the todo tool',
        };
    }

    private function describeWrite(array $arguments): string
    {
        $todos = $arguments['todos'] ?? [];
        $count = is_array($todos) ? count($todos) : 0;
        if ($count === 0) {
            return 'Clear the todo list';
        }
        $suffix = $count === 1 ? '' : 's';
        return "Update the todo list ({$count} item{$suffix})";
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doWrite(array $arguments, ?int $taskId): ToolResult
    {
        $todos = $arguments['todos'] ?? null;
        $shape = $this->validateTodosArray($todos);
        if ($shape instanceof ToolResult) {
            return $shape;
        }

        $items = [];
        $inProgressCount = 0;
        $order = 0;
        foreach ($todos as $rawItem) {
            $built = $this->buildWriteItem($rawItem, $order);
            if ($built instanceof ToolResult) {
                return $built;
            }
            [$item, $isInProgress] = $built;
            $items[] = $item;
            if ($isInProgress) {
                $inProgressCount++;
            }
            $order++;
        }

        $state = $this->persist($taskId, new TodoState(
            version: TodoState::SCHEMA_VERSION,
            items: $items,
            updatedAt: \Carbon\CarbonImmutable::now('UTC'),
        ));

        return $this->render($state, $inProgressCount, 'write');
    }

    /**
     * @param mixed $todos
     */
    private function validateTodosArray(mixed $todos): ?ToolResult
    {
        if ($todos === null) {
            return new ToolResult(
                false,
                "For op=write, 'todos' is required (the full new todo list — pass [] to clear).",
            );
        }
        if (!is_array($todos)) {
            return new ToolResult(false, "For op=write, 'todos' must be an array.");
        }
        return null;
    }

    /**
     * @param mixed $rawItem
     * @return array{0: TodoItem, 1: bool}|ToolResult
     */
    private function buildWriteItem(mixed $rawItem, int $order): array|ToolResult
    {
        if (!is_array($rawItem)) {
            return new ToolResult(false, "For op=write, every entry of 'todos' must be an object.");
        }
        $content = trim((string) ($rawItem['content'] ?? ''));
        if ($content === '') {
            return new ToolResult(false, "For op=write, every todo requires a non-empty 'content' string.");
        }
        $statusRaw = (string) ($rawItem['status'] ?? TodoItemStatus::Pending->value);
        if (TodoItemStatus::tryFrom($statusRaw) === null) {
            return new ToolResult(
                false,
                "For op=write, every todo's 'status' must be one of: pending, in_progress, completed. Got: '{$statusRaw}'.",
            );
        }
        return [
            TodoItem::fromLlmInput($rawItem, $order),
            $statusRaw === TodoItemStatus::InProgress->value,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doAdd(array $arguments, ?int $taskId): ToolResult
    {
        $item = $arguments['item'] ?? null;
        $shape = $this->validateAddItem($item);
        if ($shape instanceof ToolResult) {
            return $shape;
        }

        $content = trim((string) ($item['content'] ?? ''));
        $statusRaw = (string) ($item['status'] ?? TodoItemStatus::Pending->value);

        $current = $taskId !== null
            ? $this->registry->forTask($taskId)->read()
            : TodoState::empty();
        $existingIds = $this->collectIds($current->items);

        $suppliedId = isset($item['id']) && (string) $item['id'] !== ''
            ? (string) $item['id']
            : null;

        if ($suppliedId !== null && isset($existingIds[$suppliedId])) {
            return new ToolResult(
                false,
                "For op=add, 'item.id' '{$suppliedId}' is already in the list. Use op=set_status to flip its status.",
            );
        }

        $resolvedId = $suppliedId ?? $this->uniqueSlug($content, $existingIds);

        $newItem = TodoItem::fromLlmInput(
            ['id' => $resolvedId] + $item,
            count($current->items),
            idPrefix: '',
        );

        $state = $taskId !== null
            ? $this->registry->forTask($taskId)->append($newItem)
            : new TodoState(
                version: TodoState::SCHEMA_VERSION,
                items: [$newItem],
                updatedAt: \Carbon\CarbonImmutable::now('UTC'),
            );

        $inProgressCount = $this->countInProgress($state->items);
        return $this->render($state, $inProgressCount, 'add');
    }

    /**
     * @param mixed $item
     */
    private function validateAddItem(mixed $item): ?ToolResult
    {
        if ($item === null) {
            return new ToolResult(
                false,
                "For op=add, 'item' is required (an object with at least 'content'; may also include 'id', 'activeForm', 'status').",
            );
        }
        if (!is_array($item)) {
            return new ToolResult(false, "For op=add, 'item' must be an object.");
        }
        $content = trim((string) ($item['content'] ?? ''));
        if ($content === '') {
            return new ToolResult(false, "For op=add, 'item.content' must be a non-empty string.");
        }
        $statusRaw = (string) ($item['status'] ?? TodoItemStatus::Pending->value);
        if (TodoItemStatus::tryFrom($statusRaw) === null) {
            return new ToolResult(
                false,
                "For op=add, 'item.status' must be one of: pending, in_progress, completed. Got: '{$statusRaw}'.",
            );
        }
        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doSetStatus(array $arguments, ?int $taskId): ToolResult
    {
        $parsed = $this->parseSetStatusArgs($arguments);
        if ($parsed instanceof ToolResult) {
            return $parsed;
        }
        [$id, $status] = $parsed;
        $result = $this->lookupAndUpdateStatus($id, $status, $taskId);
        return $result instanceof ToolResult
            ? $result
            : $this->render($result, $this->countInProgress($result->items), 'set_status');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: TodoItemStatus}|ToolResult
     */
    private function parseSetStatusArgs(array $arguments): array|ToolResult
    {
        $id = trim((string) ($arguments['id'] ?? ''));
        if ($id === '') {
            return new ToolResult(
                false,
                "For op=set_status, 'id' is required (top-level; returned in data.items[i].id from earlier ops).",
            );
        }
        $status = $this->validateStatusValue(trim((string) ($arguments['status'] ?? '')));
        return $status instanceof ToolResult ? $status : [$id, $status];
    }

    /**
     * @return TodoItemStatus|ToolResult
     */
    private function validateStatusValue(string $statusRaw): TodoItemStatus|ToolResult
    {
        if ($statusRaw === '') {
            return new ToolResult(
                false,
                "For op=set_status, 'status' is required (one of: pending, in_progress, completed).",
            );
        }
        $status = TodoItemStatus::tryFrom($statusRaw);
        return $status ?? new ToolResult(
            false,
            "For op=set_status, 'status' must be one of: pending, in_progress, completed. Got: '{$statusRaw}'.",
        );
    }

    private function lookupAndUpdateStatus(string $id, TodoItemStatus $status, ?int $taskId): TodoState|ToolResult
    {
        $missing = $this->ensureItemExists($id, $taskId);
        if ($missing !== null) {
            return $missing;
        }
        return $this->applyStatusUpdate($id, $status, $taskId);
    }

    private function ensureItemExists(string $id, ?int $taskId): ?ToolResult
    {
        if ($taskId === null) {
            return new ToolResult(false, "Todo item '{$id}' not found.");
        }
        if ($this->registry->forTask($taskId)->read()->find($id) === null) {
            return new ToolResult(false, "Todo item '{$id}' not found.");
        }
        return null;
    }

    private function applyStatusUpdate(string $id, TodoItemStatus $status, int $taskId): TodoState|ToolResult
    {
        try {
            return $this->registry->forTask($taskId)->updateStatus(
                $id,
                $status,
                $this->inProgressGuard($id, $status),
            );
        } catch (TodoGuardException $e) {
            return new ToolResult(false, $e->getMessage());
        }
    }

    private function inProgressGuard(string $id, TodoItemStatus $status): \Closure
    {
        return function (TodoState $post) use ($id, $status): void {
            if ($status !== TodoItemStatus::InProgress) {
                return;
            }
            $ids = [];
            foreach ($post->items as $item) {
                if ($item->status === TodoItemStatus::InProgress) {
                    $ids[] = (string) $item->id;
                }
            }
            if (count($ids) > 1) {
                throw new TodoGuardException(
                    "Cannot mark '{$id}' as '{$status->value}' — would leave "
                    . count($ids) . ' items in_progress. Maintain exactly one. '
                    . 'Currently in_progress: [' . implode(', ', $ids) . '].',
                );
            }
        };
    }

    private function doRead(?int $taskId): ToolResult
    {
        if ($taskId === null) {
            return $this->render(TodoState::empty(), null, 'read');
        }
        return $this->render($this->registry->forTask($taskId)->read(), null, 'read');
    }

    private function persist(?int $taskId, TodoState $state): TodoState
    {
        if ($taskId === null) {
            return $state;
        }
        $this->registry->forTask($taskId)->replace($state);
        return $state;
    }

    /**
     * @param list<TodoItem> $items
     * @return array<string, true>
     */
    private function collectIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item->id !== null) {
                $ids[$item->id] = true;
            }
        }
        return $ids;
    }

    /**
     * @param array<string, true> $existingIds
     */
    private function uniqueSlug(string $content, array $existingIds): string
    {
        $base = TodoItem::slug($content);
        if (!isset($existingIds[$base])) {
            return $base;
        }
        $i = 2;
        while (isset($existingIds["{$base}-{$i}"])) {
            $i++;
        }
        return "{$base}-{$i}";
    }

    /**
     * @param list<TodoItem> $items
     */
    private function countInProgress(array $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            if ($item->status === TodoItemStatus::InProgress) {
                $n++;
            }
        }
        return $n;
    }

    private function render(TodoState $state, ?int $inProgressCount, string $op): ToolResult
    {
        $itemsPayload = array_map(static fn(TodoItem $i): array => $i->toArray(), $state->items);

        if ($state->items === []) {
            $content = $op === 'read' ? 'Todo list is empty.' : 'Todo list cleared.';
            return new ToolResult(
                true,
                $content,
                ['op' => $op, 'items' => $itemsPayload],
            );
        }

        $rendered = $this->renderMarkdown($state);
        $warning = $inProgressCount !== null && $inProgressCount > 1
            ? "\n\n**Note:** {$inProgressCount} items are marked `in_progress` at once. "
              . 'Maintain exactly one `in_progress` item at a time.'
            : '';

        return new ToolResult(
            true,
            $rendered . $warning,
            ['op' => $op, 'items' => $itemsPayload],
        );
    }

    private function renderMarkdown(TodoState $state): string
    {
        $lines = [];
        foreach ($state->items as $item) {
            $marker = match ($item->status) {
                TodoItemStatus::Completed  => '[x]',
                TodoItemStatus::InProgress => '[~]',
                TodoItemStatus::Pending    => '[ ]',
                default                    => '[ ]',
            };
            $lines[] = "- {$marker} {$item->content}";
            if ($item->activeForm !== null && $item->activeForm !== $item->content) {
                $lines[] = "      _({$item->activeForm})_";
            }
        }
        return "Todo list:\n" . implode("\n", $lines);
    }
}
