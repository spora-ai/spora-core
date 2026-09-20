<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\PrincipalContext;
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
    description: 'Manage a structured task list for the current work. '
               . 'Use proactively for multi-step tasks (3+ steps); skip trivial '
               . 'single-step requests, informational questions, or pure conversation. '
               . 'Mark an item `in_progress` BEFORE you start work on it, and mark it '
               . '`completed` immediately after — do not batch completions. Maintain '
               . 'exactly one `in_progress` item at any moment; if you find yourself '
               . 'with zero or more than one, fix the list before continuing. Remove '
               . 'items that are no longer relevant; do not keep stale entries. Each '
               . 'item carries both forms: `content` (imperative, e.g. "Run the '
               . 'migration") and `activeForm` (present continuous, e.g. "Running '
               . 'the migration"). If work on an item is blocked, leave it '
               . '`in_progress` and add a new item describing the blocker; never mark '
               . 'an item complete unless its work is fully done. '
               . 'Select the operation via `op`: "write" replaces the whole list '
               . '(pass an empty `todos` array to clear), "add" appends a single '
               . 'item (pass `id` explicitly when you want a stable handle for later '
               . '`set_status` calls; otherwise one is generated from `content` with '
               . '`-2`, `-3` suffixes on collision), "set_status" flips one item\'s '
               . 'status by id (idempotent — re-marking `completed` is fine), and '
               . '"read" returns the current state without mutating. Every op '
               . 'returns the full new state — use that echo to confirm the call '
               . 'took effect.',
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
    description: 'Update the status of one item by id. Order preserved; idempotent on the same status.',
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
    description: 'For op=set_status: the `id` of the item to update. Returned in `data.items[i].id` from earlier ops.',
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
            'read'       => $this->doRead($arguments, $taskId),
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
        return $count === 0
            ? 'Clear the todo list'
            : "Update the todo list ({$count} item" . ($count === 1 ? '' : 's') . ')';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doWrite(array $arguments, ?int $taskId): ToolResult
    {
        $todos = $arguments['todos'] ?? null;
        if ($todos === null) {
            return new ToolResult(false, "Required argument 'todos' is missing.");
        }
        if (!is_array($todos)) {
            return new ToolResult(false, "Argument 'todos' must be an array.");
        }

        $items = [];
        $inProgressCount = 0;
        $order = 0;
        foreach ($todos as $rawItem) {
            if (!is_array($rawItem)) {
                return new ToolResult(false, 'Every todo item must be an object.');
            }
            $content = trim((string) ($rawItem['content'] ?? ''));
            if ($content === '') {
                return new ToolResult(false, "Every todo item requires a non-empty 'content' string.");
            }
            $statusRaw = (string) ($rawItem['status'] ?? TodoItemStatus::Pending->value);
            if (TodoItemStatus::tryFrom($statusRaw) === null) {
                return new ToolResult(
                    false,
                    "Todo item status must be one of: pending, in_progress, completed. Got: '{$statusRaw}'.",
                );
            }
            $items[] = TodoItem::fromLlmInput($rawItem, $order);
            if ($statusRaw === TodoItemStatus::InProgress->value) {
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
     * @param array<string, mixed> $arguments
     */
    private function doAdd(array $arguments, ?int $taskId): ToolResult
    {
        $item = $arguments['item'] ?? null;
        if ($item === null) {
            return new ToolResult(false, "Required argument 'item' is missing for op=add.");
        }
        if (!is_array($item)) {
            return new ToolResult(false, "Argument 'item' must be an object.");
        }

        $content = trim((string) ($item['content'] ?? ''));
        if ($content === '') {
            return new ToolResult(false, "Argument 'item' requires a non-empty 'content' string.");
        }

        $statusRaw = (string) ($item['status'] ?? TodoItemStatus::Pending->value);
        if (TodoItemStatus::tryFrom($statusRaw) === null) {
            return new ToolResult(
                false,
                "Todo item status must be one of: pending, in_progress, completed. Got: '{$statusRaw}'.",
            );
        }

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
                "Todo item id '{$suppliedId}' is already in the list. Use op=set_status to flip its status.",
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
     * @param array<string, mixed> $arguments
     */
    private function doSetStatus(array $arguments, ?int $taskId): ToolResult
    {
        $id = trim((string) ($arguments['id'] ?? ''));
        if ($id === '') {
            return new ToolResult(false, "Required argument 'id' is missing for op=set_status.");
        }
        $statusRaw = trim((string) ($arguments['status'] ?? ''));
        $status = TodoItemStatus::tryFrom($statusRaw);
        if ($status === null) {
            return new ToolResult(
                false,
                "Argument 'status' must be one of: pending, in_progress, completed. Got: '{$statusRaw}'.",
            );
        }

        if ($taskId === null) {
            return new ToolResult(false, "Todo item '{$id}' not found.");
        }

        $store = $this->registry->forTask($taskId);
        $current = $store->read();
        if ($current->find($id) === null) {
            return new ToolResult(false, "Todo item '{$id}' not found.");
        }

        $state = $store->updateStatus($id, $status);

        return $this->render($state, $this->countInProgress($state->items), 'set_status');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doRead(array $arguments, ?int $taskId): ToolResult
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
