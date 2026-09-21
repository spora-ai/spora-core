<?php

declare(strict_types=1);

namespace Spora\Todo;

use Carbon\CarbonImmutable;
use Closure;
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
        return self::decodeData(Capsule::table('tasks')
            ->where('id', $this->taskId)
            ->value('data'));
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

    /**
     * Flip one item's status under a row lock so concurrent `set_status`
     * calls cannot race past each other.
     *
     * The read-mutate-write cycle is wrapped in a single transaction with
     * `lockForUpdate`, so a second caller reading after the first commit
     * sees the updated state before its guard decides whether to apply.
     *
     * The optional `$guard` callback receives the proposed post-state and
     * may throw {@see TodoGuardException} to reject the write; the throw
     * aborts the surrounding transaction so the database row is unchanged.
     * Without a guard the call behaves like the legacy "happy path"
     * updateStatus: idempotent, no-op on missing id or already-target status.
     */
    public function updateStatus(string $id, TodoItemStatus $status, ?Closure $guard = null): TodoState
    {
        $result = TodoState::empty();
        Capsule::connection()->transaction(function () use ($id, $status, $guard, &$result): void {
            $row = Task::where('id', $this->taskId)->lockForUpdate()->first();
            $current = $row === null ? TodoState::empty() : self::decodeData($row->data);

            $items = [];
            $changed = false;
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
                $result = $current;
                return;
            }

            $next = new TodoState(
                version: TodoState::SCHEMA_VERSION,
                items: $items,
                updatedAt: CarbonImmutable::now('UTC'),
            );

            if ($guard !== null) {
                $guard($next);
            }

            $this->writeState($next);
            $result = $next;
        });

        return $result;
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

    /**
     * Decode the `tasks.data` JSON column into a {@see TodoState}. Used by
     * both the unlocked `read()` path (raw query, returns JSON string) and
     * the locked path inside `updateStatus` (Eloquent, returns the cast
     * array) so the decoding rules live in one place.
     */
    private static function decodeData(mixed $raw): TodoState
    {
        $decoded = self::decodeRaw($raw);
        if ($decoded === null || !isset($decoded['todos']) || !is_array($decoded['todos'])) {
            return TodoState::empty();
        }
        return TodoState::fromArray($decoded['todos']);
    }

    private static function decodeRaw(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
