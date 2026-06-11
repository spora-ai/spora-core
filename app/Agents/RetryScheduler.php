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
 * Extracted from {@see Orchestrator} so the orchestrator stays under the
 * SonarQube `php:S1448` method-count cap.
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
        private readonly Orchestrator $orchestrator,
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

        $this->dispatchRetryTask($agent, $failedTask, $rootTaskId, $retryCount, $retryAfterMinutes, $maxRetries);
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
        Agent $agent,
        Task $failedTask,
        int $rootTaskId,
        int $retryCount,
        int $retryAfterMinutes,
        int $maxRetries,
    ): void {
        try {
            // Create the retry task directly in QUEUED state instead of routing
            // through Orchestrator::start(), which would tick the LLM immediately
            // in Sync mode and defeat the whole point of retry_after scheduling.
            $retryTask = Task::create([
                'agent_id'         => $agent->id,
                'user_id'          => $agent->user_id,
                'status'           => 'QUEUED',
                'user_prompt'      => $failedTask->user_prompt,
                'step_count'       => 0,
                'max_steps'        => $failedTask->max_steps,
                'retry_of_task_id' => $rootTaskId,
                'retry_count'      => $retryCount,
                'retry_after'      => date(Orchestrator::DB_TIMESTAMP_FORMAT, time() + $retryAfterMinutes * 60),
            ]);

            $this->orchestrator->appendHistory($retryTask->id, 'user', $failedTask->user_prompt);

            $failedTask->update([
                'retry_after' => $retryTask->retry_after,
            ]);

            $this->notificationService?->notifyRetryQueued($retryTask, $retryCount, $maxRetries);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to schedule auto-retry', [
                'task_id'          => $failedTask->id,
                'exception_class'  => get_class($e),
                'message'          => $e->getMessage(),
            ]);
        }
    }
}
