<?php

declare(strict_types=1);

namespace Spora\Agents;

use Psr\Log\LoggerInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Throwable;

/**
 * Schedules and dispatches auto-retries for failed tasks.
 *
 * Package-private collaborator: constructed and called only by
 * {@see Orchestrator}.
 */
final class RetryScheduler
{
    /** Error codes that qualify for auto-retry. */
    public const RETRYABLE_ERROR_CODES = [
        'RATE_LIMIT',
        'SERVER_OVERLOADED',
        'SERVER_ERROR',
        'GATEWAY_ERROR',
        'AUTH_ERROR',
        'LLM_TIMEOUT',
        'ORPHANED',
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?NotificationService $notificationService = null,
    ) {}

    public function scheduleAutoRetry(Task $failedTask, string $errorCode): void
    {
        if (!in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)) {
            return;
        }

        $agent = $this->resolveRetryAgent($failedTask);
        if ($agent === null) {
            return;
        }

        $retryAfterMinutes = $agent->retry_after_minutes ?? 0;
        $maxRetries = $agent->max_retries ?? 0;

        $rootTaskId = $failedTask->retry_of_task_id ?? $failedTask->id;
        $retryCount = (int) ($failedTask->retry_count ?? 0) + 1;

        $this->dispatchRetryTask($failedTask, $rootTaskId, $retryCount, $retryAfterMinutes, $maxRetries);
    }

    private function resolveRetryAgent(Task $failedTask): ?Agent
    {
        /** @var Agent|null $agent */
        $agent = Agent::find($failedTask->agent_id);
        if ($agent === null) {
            return null;
        }

        $retryAfterMinutes = (int) ($agent->retry_after_minutes ?? 0);
        $maxRetries = (int) ($agent->max_retries ?? 0);
        $retryCount = (int) ($failedTask->retry_count ?? 0) + 1;
        $isWithinRetryBudget = $retryAfterMinutes > 0
            && $maxRetries > 0
            && $retryCount <= $maxRetries;

        return $isWithinRetryBudget ? $agent : null;
    }

    private function dispatchRetryTask(
        Task $failedTask,
        int $rootTaskId,
        int $retryCount,
        int $retryAfterMinutes,
        int $maxRetries,
    ): void {
        try {
            // Schedule the retry IN PLACE on the failed task itself rather than
            // spawning a separate QUEUED row. The task keeps its full history
            // (the LLM re-sees the prior failed turn when it ticks), its URL
            // doesn't change, and the worker's main QUEUED loop correctly
            // skips it because `retry_of_task_id IS NULL` is part of its claim
            // predicate. WorkerQueueProcessor::processRetryQueue() picks the
            // task up once `retry_after` elapses and calls Orchestrator::retry()
            // to reset error fields and start the loop again.
            $retryAfter = date(Orchestrator::DB_TIMESTAMP_FORMAT, time() + $retryAfterMinutes * 60);

            $failedTask->update([
                'retry_count'      => $retryCount,
                'retry_of_task_id' => $rootTaskId,
                'retry_after'      => $retryAfter,
            ]);

            $failedTask->refresh();

            $this->notificationService?->notifyRetryQueued($failedTask, $retryCount, $maxRetries);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to schedule auto-retry', [
                'task_id'          => $failedTask->id,
                'exception_class'  => get_class($e),
                'message'          => $e->getMessage(),
            ]);
        }
    }
}
