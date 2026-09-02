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
     *
     * Replaces the legacy user-keyed `publish()` for task events.
     */
    public function publishForPrincipal(int $taskId, int $principalId, array $taskData): bool;

    /**
     * @deprecated Use {@see publishForPrincipal()} for task events.
     *             Retained during the Plan B migration window; will be
     *             removed once all task-channel callers are swept.
     *
     * Publish to the user-keyed topic `user/{userId}/tasks`. Only the
     * trigger user (or no one, for system-generated tasks) receives the
     * event — group peers are invisible.
     */
    public function publish(int $taskId, int $userId, array $taskData): bool;

    /**
     * Publish a user-scoped notification to the Mercure hub.
     * Topic: user/{userId}/notifications
     */
    public function publishToUser(int $userId, array $data): bool;
}
