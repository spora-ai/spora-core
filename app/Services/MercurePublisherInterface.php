<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Interface for real-time task state publishing via Mercure SSE.
 */
interface MercurePublisherInterface
{
    /**
     * Publish a task state change to the Mercure hub keyed by principal owner.
     * Topic: principal/{principalId}/tasks — every principal viewer
     * (group members included) with `subscribe` rights receives the event.
     */
    public function publishForPrincipal(int $taskId, int $principalId, array $taskData): bool;

    /**
     * Publish a user-scoped notification to the Mercure hub.
     * Topic: user/{userId}/notifications
     */
    public function publishToUser(int $userId, array $data): bool;
}
