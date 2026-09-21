<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Todo\TodoItem;
use Spora\Todo\TodoItemStatus;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Validates the per-op argument shape for {@see TodoTool}.
 *
 * Kept as a separate class so the tool's own method count stays under
 * the per-class limit while still splitting the multi-step validation
 * chains far enough that each helper satisfies SonarQube's <=3-return
 * rule.
 */
final class TodoItemValidator
{
    /**
     * @param mixed $input
     */
    public function validateTodosArray(mixed $input): ?ToolResult
    {
        if ($input === null) {
            return new ToolResult(
                false,
                "For op=write, 'todos' is required (the full new todo list — pass [] to clear).",
            );
        }
        if (!is_array($input)) {
            return new ToolResult(false, "For op=write, 'todos' must be an array.");
        }
        return null;
    }

    /**
     * @param mixed $rawItem
     * @return array{0: TodoItem, 1: bool}|ToolResult
     */
    public function buildWriteItem(mixed $rawItem, int $order): array|ToolResult
    {
        if (!is_array($rawItem)) {
            return new ToolResult(false, "For op=write, every entry of 'todos' must be an object.");
        }
        $content = trim((string) ($rawItem['content'] ?? ''));
        if ($content === '') {
            return new ToolResult(false, "For op=write, every todo requires a non-empty 'content' string.");
        }
        return $this->composeWriteItem($rawItem, $order);
    }

    /**
     * @param array<string, mixed> $rawItem
     * @return array{0: TodoItem, 1: bool}|ToolResult
     */
    private function composeWriteItem(array $rawItem, int $order): array|ToolResult
    {
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
     * @param mixed $item
     */
    public function validateForAdd(mixed $item): ?ToolResult
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
        return $this->validateAddItemContent($item);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function validateAddItemContent(array $item): ?ToolResult
    {
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
     * @return array{0: string, 1: TodoItemStatus}|ToolResult
     */
    public function parseSetStatusArgs(array $arguments): array|ToolResult
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
}
