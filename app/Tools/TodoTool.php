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
 * The agent's working plan, persisted on `tasks.data.todos` and
 * surfaced to the operator UI. Mirrors Claude Code's `TodoWrite`
 * semantics so any model trained on it can be dropped into Spora
 * without prompt-engineering.
 *
 * Lifecycle (verbatim in the description):
 *
 *   - Use proactively for 3+ step tasks; skip trivial single-step work.
 *   - Mark an item `in_progress` before starting it; complete
 *     immediately after — don't batch.
 *   - Maintain exactly one `in_progress` at a time.
 *   - Remove items that are no longer relevant; don't keep stale entries.
 *   - If blocked, keep the item `in_progress` and add a new item
 *     describing the blocker; never mark complete unless the work is done.
 *
 * The tool result echoes the new state back so the model sees its own
 * write — Claude Code's "no-op" pattern. We deliberately do NOT inject
 * the todo state into the system prompt: see the "Why tool-only" note
 * in `docs/`.
 */
#[Tool(
    name: 'todo',
    description: 'Use this tool to create and manage a structured task list for the current work. '
               . 'Use it proactively for multi-step tasks (3+ steps) — for trivial single-step '
               . 'requests, informational questions, or pure conversation, skip it. '
               . 'Mark an item `in_progress` BEFORE you start work on it, and mark it `completed` '
               . 'immediately after — do not batch completions. Maintain exactly one `in_progress` '
               . 'item at any moment; if you find yourself with zero or more than one, fix the '
               . 'list before continuing. Remove items that are no longer relevant; do not keep '
               . 'stale entries. Each item carries both forms: `content` (imperative, e.g. '
               . '"Run the migration") and `activeForm` (present continuous, e.g. "Running the '
               . 'migration"). If work on an item is blocked, leave it `in_progress` and add a '
               . 'new item describing the blocker; never mark an item complete unless its work is '
               . 'fully done. An empty `todos` array clears the list.',
    displayName: 'Task List',
    category: 'meta',
    icon: 'list-checks',
)]
#[ToolOperation(
    name: 'write',
    description: 'Replace the current todo list with the supplied list. Pass an empty array to clear it.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'todos',
    type: 'array',
    description: 'The new todo list. Empty array clears the list.',
    required: true,
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
        return $this->doWrite($arguments, $agentId, $taskId);
    }

    public function describeAction(array $arguments): string
    {
        $todos = $arguments['todos'] ?? [];
        $count = is_array($todos) ? count($todos) : 0;
        return $count === 0
            ? 'Clear the todo list'
            : "Update the todo list ({$count} item" . ($count === 1 ? '' : 's') . ')';
    }

    public function write(array $arguments): ToolResult // NOSONAR php:S1172 — required by HasOperations dispatch trait
    {
        return $this->doWrite($arguments, null, null);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doWrite(array $arguments, ?int $agentId, ?int $taskId): ToolResult
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

        if ($taskId === null) {
            return new ToolResult(false, 'Cannot persist todos without a task id.');
        }

        $store = $this->registry->forTask($taskId);
        $state = new TodoState(
            version: TodoState::SCHEMA_VERSION,
            items: $items,
            updatedAt: \Carbon\CarbonImmutable::now('UTC'),
        );
        $store->replace($state);

        $rendered = $this->renderMarkdown($state);
        $warning = $inProgressCount > 1
            ? "\n\n**Note:** {$inProgressCount} items are marked `in_progress` at once. "
              . 'Maintain exactly one `in_progress` item at a time.'
            : '';

        return new ToolResult(
            true,
            $rendered . $warning,
            [
                'items' => array_map(static fn(TodoItem $i): array => $i->toArray(), $state->items),
            ],
        );
    }

    /**
     * Render the markdown the model and operator UI both see.
     */
    private function renderMarkdown(TodoState $state): string
    {
        if ($state->items === []) {
            return 'Todo list cleared.';
        }

        $lines = [];
        foreach ($state->items as $item) {
            $marker = match ($item->status) {
                TodoItemStatus::Completed   => '[x]',
                TodoItemStatus::InProgress  => '[~]',
                TodoItemStatus::Pending     => '[ ]',
                default                     => '[ ]',
            };
            $lines[] = "- {$marker} {$item->content}";
            if ($item->activeForm !== null && $item->activeForm !== $item->content) {
                $lines[] = "      _({$item->activeForm})_";
            }
        }
        return "Todo list:\n" . implode("\n", $lines);
    }
}
