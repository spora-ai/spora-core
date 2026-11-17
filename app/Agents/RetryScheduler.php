<?php

declare(strict_types=1);

namespace Spora\Agents;

use Psr\Log\LoggerInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Throwable;

/**
 * Schedules and dispatches auto-retries for failed tasks.
 *
 * Called by the orchestrator when a run fails, and by the worker reaper
 * when the worker running a task vanished.
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
        'WORKER_DISCONNECTED',
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?NotificationService $notificationService = null,
        private readonly WorkerRuntimeMode $workerRuntimeMode = WorkerRuntimeMode::Server,
    ) {}

    public function scheduleAutoRetry(Task $failedTask, string $errorCode): void
    {
        if (!in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)) {
            return;
        }

        // Only the server worker claims scheduled retries — the retry queue
        // lives in its daemon. In client-worker mode nothing would ever
        // pick the row up, so arming it there would promise a retry that
        // never comes, and a parent waiting on that retry would wait for
        // it forever.
        if ($this->workerRuntimeMode === WorkerRuntimeMode::Client) {
            return;
        }

        try {
            $agent = $this->resolveRetryAgent($failedTask);
            if ($agent === null) {
                return;
            }

            $retryAfterMinutes = $agent->retry_after_minutes ?? 0;
            $maxRetries = $agent->max_retries ?? 0;

            $rootTaskId = $failedTask->retry_of_task_id ?? $failedTask->id;
            $retryCount = (int) ($failedTask->retry_count ?? 0) + 1;

            $this->dispatchRetryTask($failedTask, $rootTaskId, $retryCount, $retryAfterMinutes, $maxRetries);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to schedule auto-retry', [
                'task_id'          => $failedTask->id,
                'exception_class'  => get_class($e),
                'message'          => $e->getMessage(),
            ]);
        }
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
        // Schedule the retry IN PLACE on the failed task itself rather than
        // spawning a separate QUEUED row. The task keeps its full history
        // (the LLM re-sees the prior failed turn when it ticks), its URL
        // doesn't change, and the worker's main QUEUED loop correctly
        // skips it because `retry_of_task_id IS NULL` is part of its claim
        // predicate. WorkerQueueProcessor::processRetryQueue() picks the
        // task up once `retry_after` elapses and calls Orchestrator::retry()
        // to reset error fields and start the loop again.
        //
        // `retry_after` is a UTC column: the worker's claim query reads
        // it as UTC, so the stamp has to be UTC too. The process that
        // schedules the retry is not the worker daemon and does not pin
        // the timezone, so `date()` would write the host's local wall
        // clock here — and the worker would compare it against UTC,
        // firing the retry an hour off on every host that is not UTC.
        $retryAfter = gmdate(Orchestrator::DB_TIMESTAMP_FORMAT, time() + $retryAfterMinutes * 60);

        // The condition rides on the write, not on a read before it: the
        // row has to still be FAILED and still carry no pending retry at
        // the moment the markers land. A manual `Orchestrator::retry()`
        // can run between the two — it flips the row to QUEUED and clears
        // the markers — and a QUEUED row that carries the chain marker is
        // claimed by nobody: the main queue skips rows with a marker and
        // the retry queue only looks at FAILED rows.
        $affected = Task::where('id', $failedTask->id)
            ->where('status', 'FAILED')
            ->whereNull('retry_after')
            ->update([
                'retry_count'      => $retryCount,
                'retry_of_task_id' => $rootTaskId,
                'retry_after'      => $retryAfter,
            ]);

        if ($affected === 0) {
            return;
        }

        $failedTask->refresh();

        $this->notificationService?->notifyRetryQueued($failedTask, $retryCount, $maxRetries);
    }
}
