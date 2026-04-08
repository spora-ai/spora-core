<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Interface for real-time task state publishing via Mercure SSE.
 */
interface MercurePublisherInterface
{
    /**
     * Publish a task state change to the Mercure hub.
     */
    public function publish(int $taskId, array $taskData): bool;
}
