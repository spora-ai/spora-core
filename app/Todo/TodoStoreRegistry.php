<?php

declare(strict_types=1);

namespace Spora\Todo;

/**
 * Caches a {@see TodoStore} per taskId for the lifetime of a request.
 * Avoids a redundant `tasks.data` fetch on every read inside a single tick.
 */
final class TodoStoreRegistry
{
    /** @var array<int, TodoStore> */
    private array $cache = [];

    public function forTask(int $taskId): TodoStore
    {
        if (!isset($this->cache[$taskId])) {
            $this->cache[$taskId] = new TodoStore($taskId);
        }
        return $this->cache[$taskId];
    }

    public function flush(int $taskId): void
    {
        unset($this->cache[$taskId]);
    }
}
