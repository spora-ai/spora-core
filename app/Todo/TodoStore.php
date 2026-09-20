<?php

declare(strict_types=1);

namespace Spora\Todo;

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Agents\Orchestrator;
use Spora\Models\Task;

/**
 * Loads and persists the todo state attached to a single Task row.
 *
 * The canonical store is `tasks.data.todos` (a JSON column already cast
 * to `array` on the Eloquent model — see `Task::$casts`). Concurrency
 * is handled by a `lockForUpdate` transaction so a parallel `TodoTool`
 * write cannot race a snapshot read.
 */
final class TodoStore
{
    public function __construct(
        private readonly int $taskId,
    ) {}

    public function read(): TodoState
    {
        $raw = Capsule::table('tasks')
            ->where('id', $this->taskId)
            ->value('data');

        if (!is_string($raw) || $raw === '') {
            return TodoState::empty();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return TodoState::empty();
        }

        if (!is_array($decoded) || !isset($decoded['todos']) || !is_array($decoded['todos'])) {
            return TodoState::empty();
        }

        return TodoState::fromArray($decoded['todos']);
    }

    public function replace(TodoState $next): void
    {
        $this->writeState($next);
    }

    public function append(TodoItem $item): TodoState
    {
        $current = $this->read();
        $items = $current->items;
        $items[] = $item;
        $next = new TodoState(
            version: TodoState::SCHEMA_VERSION,
            items: $items,
            updatedAt: CarbonImmutable::now('UTC'),
        );
        $this->writeState($next);
        return $next;
    }

    public function updateStatus(string $id, TodoItemStatus $status): TodoState
    {
        $current = $this->read();
        $changed = false;
        $items = [];
        foreach ($current->items as $existing) {
            if ($existing->id === $id) {
                if ($existing->status !== $status) {
                    $changed = true;
                    $items[] = new TodoItem(
                        id: $existing->id,
                        content: $existing->content,
                        activeForm: $existing->activeForm,
                        status: $status,
                        order: $existing->order,
                    );
                    continue;
                }
                $items[] = $existing;
                continue;
            }
            $items[] = $existing;
        }

        if (!$changed) {
            return $current;
        }

        $next = new TodoState(
            version: TodoState::SCHEMA_VERSION,
            items: $items,
            updatedAt: CarbonImmutable::now('UTC'),
        );
        $this->writeState($next);
        return $next;
    }

    private function writeState(TodoState $next): void
    {
        $now = CarbonImmutable::now('UTC')->toIso8601String();
        $payload = ['todos' => array_merge($next->toArray(), ['updated_at' => $now])];

        Capsule::connection()->transaction(function () use ($payload, $now): void {
            $row = Task::where('id', $this->taskId)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }

            $existing = is_array($row->data) ? $row->data : [];
            $existing['todos'] = array_merge($payload['todos'], ['updated_at' => $now]);

            Capsule::table('tasks')
                ->where('id', $this->taskId)
                ->update([
                    'data'       => json_encode($existing, JSON_THROW_ON_ERROR),
                    'updated_at' => gmdate(Orchestrator::DB_TIMESTAMP_FORMAT),
                ]);
        });
    }
}
