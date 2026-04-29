<?php

declare(strict_types=1);

namespace Spora\Services;

use RuntimeException;

/**
 * Service interface for scheduled run management.
 */
interface ScheduledRunServiceInterface
{
    /**
     * Get all scheduled runs for an agent.
     *
     * @return list<array>|null Returns null if agent not found
     */
    public function getRunsForAgent(int $agentId, int $userId): ?array;

    /**
     * Create a new scheduled run.
     *
     * @return array{scheduled_run: array}
     */
    public function createRun(int $agentId, int $userId, array $data): array;

    /**
     * Get a specific scheduled run.
     *
     * @return array{scheduled_run: array}|null Returns null if not found
     */
    public function getRun(int $runId, int $agentId, int $userId): ?array;

    /**
     * Update a scheduled run.
     *
     * @return array{scheduled_run: array}|null Returns null if not found
     */
    public function updateRun(int $runId, int $agentId, int $userId, array $data): ?array;

    /**
     * Delete a scheduled run.
     *
     * @return bool True if deleted, false if not found
     */
    public function deleteRun(int $runId, int $agentId, int $userId): bool;

    /**
     * Trigger a scheduled run immediately.
     *
     * @return array{scheduled_run: array, task_id: int}
     * @throws RuntimeException If template not found or orchestration fails
     */
    public function triggerRun(int $runId, int $agentId, int $userId): array;
}
