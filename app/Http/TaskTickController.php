<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Spora\Agents\ErrorClassifier;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\RetryScheduler;
use Spora\Agents\TaskLifecyclePolicy;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Auth\AuthService;
use Spora\Http\Exceptions\TooManyRequestsException;
use Spora\Models\Task;
use Spora\Services\DbRateLimiter;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\PrincipalResolver;
use Spora\Services\TaskServiceInterface;
use Spora\Services\Text\Utf8Sanitizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Owns POST /api/v1/tasks/{taskId}/tick — client-worker mode's per-task
 * tick driver. Split out of TaskController so the latter stays under
 * the S1448 method-count ceiling. Runtime contract is unchanged.
 */
final class TaskTickController
{
    private const ERR_TASK_NOT_FOUND = 'Task not found.';

    private const TICK_RATE_LIMIT_MAX = 60;
    private const TICK_RATE_LIMIT_WINDOW_SECONDS = 60;

    /** Lease owner prefix the /tick controller writes to tasks.lease_owner. */
    private const LEASE_OWNER_PREFIX = 'user:';

    public function __construct(
        private readonly AuthService $authService,
        private readonly TaskServiceInterface $taskService,
        private readonly WorkerRuntimeMode $workerRuntimeMode,
        private readonly DbRateLimiter $rateLimiter,
        private readonly MercurePublisherInterface $mercure,
        private readonly OrchestratorInterface $orchestrator,
        private readonly ErrorClassifier $errorClassifier,
        private readonly RetryScheduler $retryScheduler,
        private readonly ?NotificationService $notificationService,
        private readonly LoggerInterface $logger,
        private readonly int $tickLeaseSeconds,
        private readonly ?PrincipalResolver $principalResolver = null,
    ) {}

    /**
     * POST /api/v1/tasks/{taskId}/tick
     *
     * Client-worker mode only — the browser's SharedWorker drives one
     * iteration of the agent loop here, mirrors the messenger-handler
     * path of {@see \Spora\Agents\Orchestrator::tick()}, and reports
     * the resulting task back to the SPA.
     *
     * The flow:
     *   1. 404 in server mode — the route stays registered but is gated
     *      inline so server-mode installs don't expose the surface.
     *   2. Per-user rate limit (60/min) — bursts from a runaway SharedWorker
     *      must not hammer PHP-FPM.
     *   3. set_time_limit(0) — the tick blocks for the LLM round-trip.
     *   4. 404 on not-owned (matches the abort/show precedent — existence
     *      hiding rather than 403).
     *   5. 400/409 on terminal or quiescent source status, or on a row
     *      that's already RUNNING.
     *   6. CAS-claim the row inside a transaction (status=QUEUED, no
     *      live lease) → RUNNING + lease_owner + lease_expires_at.
     *      Lost the race → 409 TICK_LOST_RACE so the browser can back off.
     *   7. Publish Mercure RUNNING BEFORE the LLM call so the UI flips
     *      status immediately, then call Orchestrator::tick() with a
     *      lease-aware config so the reaper can't race this request.
     *   8. On exception, flip the row to FAILED (mirror of
     *      WorkerQueueProcessor::processQueuedTaskSync() lines 87-106)
     *      and clear the lease.
     *   9. Once the row is terminal or quiescent, clear the lease —
     *      running/quiescent tasks are the only ones the reaper should
     *      consider orphans.
     */
    #[OA\Post(
        path: '/api/v1/tasks/{taskId}/tick',
        tags: ['Tasks'],
        summary: 'Tick a task (client-worker mode only)',
        parameters: [
            new OA\Parameter(
                name: 'taskId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON envelope: `{data: {task: ...}}` with the post-tick task resource.',
            ),
            new OA\Response(
                response: 404,
                description: 'NOT_FOUND — server mode is active or task is not owned by the calling user.',
            ),
            new OA\Response(
                response: 409,
                description: 'INVALID_STATE — task is terminal, quiescent, or being ticked by another caller.',
            ),
            new OA\Response(
                response: 429,
                description: 'TOO_MANY_REQUESTS — per-user rate limit exceeded.',
            ),
        ],
    )]
    public function tick(Request $request): JsonResponse
    {
        if ($this->workerRuntimeMode !== WorkerRuntimeMode::Client) {
            return $this->notFoundResponse();
        }

        $userId = (int) $this->authService->currentUserId();
        if (!$this->rateLimiter->attempt('tick:' . $userId, self::TICK_RATE_LIMIT_MAX, self::TICK_RATE_LIMIT_WINDOW_SECONDS)) {
            throw new TooManyRequestsException('Tick rate limit exceeded.');
        }

        set_time_limit(0);

        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            return $this->runTick($taskId, $userId);
        } catch (InvalidArgumentException $e) {
            // 404 on not-owned (matches abort/show precedent — plan finding #6);
            // 409 on not-drivable status — keep the typed exceptions so the
            // surrounding `abort`/`show` helper logic still works.
            return $this->errorForException($e);
        }
    }

    private function runTick(int $taskId, int $userId): JsonResponse
    {
        $task = $this->loadDrivableTask($taskId, $userId);

        // RUNNING rows can't be re-claimed; the controller returns a
        // dedicated 409 TICK_ALREADY_RUNNING so the browser can back off
        // without trying to interpret TICK_LOST_RACE for this case.
        if ($task->status === 'RUNNING') {
            return $this->tickAlreadyRunningResponse();
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $leaseUntilCarbon = Carbon::instance($now)->modify('+' . $this->tickLeaseSeconds . ' seconds');
        $leaseOwner = self::LEASE_OWNER_PREFIX . $userId;
        $startedAt = microtime(true);

        // Server-side counterpart to the browser's [client-worker] log lines:
        // the operator's `storage/spora.log` should show one entry per tick
        // so a multi-step task is visibly N ticks, not "one big run".
        // Mirrors `WorkerQueueProcessor::processQueuedTaskSync()`'s format
        // so the operator's log greps look the same across worker modes.
        $this->logger->info('Processing task (client-worker /tick)', [
            'task_id' => $taskId,
            'lease_owner' => $leaseOwner,
            'lease_seconds' => $this->tickLeaseSeconds,
        ]);

        $claimed = $this->claimTaskForTick($task, $leaseOwner, $leaseUntilCarbon, $now);
        if ($claimed === null) {
            return $this->tickLostRaceResponse();
        }

        // Mercure publish BEFORE the LLM call so the UI sees QUEUED → RUNNING immediately.
        $this->mercure->publishForPrincipal($claimed->id, $claimed->principalOwnerId(), [
            'task_id' => $claimed->id,
            'status'  => 'RUNNING',
        ]);

        $this->runOrchestratorTickAndHandleFailure($claimed, $leaseOwner, $startedAt);
        $this->clearLeaseIfTerminal($claimed->id);

        $fresh = Task::find($claimed->id);
        $this->logger->info('Task tick completed', [
            'task_id' => $claimed->id,
            'lease_owner' => $leaseOwner,
            'status' => $fresh->status ?? 'unknown',
            'step_count' => $fresh->step_count ?? 0,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return new JsonResponse(['data' => ['task' => $this->taskService->getTask($claimed->id, $userId)]]);
    }

    /**
     * Ownership + drivability check. Throws {@see InvalidArgumentException}
     * with a stable message for not-found (mapped to 404 by the caller)
     * and for terminal / quiescent sources (mapped to 409). RUNNING
     * sources return the task itself; the caller returns the dedicated
     * 409 TICK_ALREADY_RUNNING before attempting a CAS-claim.
     */
    private function loadDrivableTask(int $taskId, int $userId): Task
    {
        // Group-scoped: any member of any principal that owns the task
        // can drive the tick. The legacy runner-only guard
        // (`tasks.user_id`) is widened to `tasks.principal_id IN
        // visiblePrincipalIds(user)` — matches the per-task action
        // gating semantics.
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)->whereIn('principal_id', $visiblePrincipalIds)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        $policy = new TaskLifecyclePolicy();
        if ($policy->isTerminal($task->status) || $policy->isQuiescent($task->status)) {
            throw new InvalidArgumentException(
                'Task is not drivable in status "' . $task->status . '".',
            );
        }

        return $task;
    }

    /**
     * CAS-claim the row inside a transaction so two browsers can't both
     * observe QUEUED + no live lease and flip the same task to RUNNING.
     * Returns null when the claim fails — the caller emits the 409.
     */
    private function claimTaskForTick(
        Task $task,
        string $leaseOwner,
        Carbon $leaseUntilCarbon,
        DateTimeImmutable $now,
    ): ?Task {
        return Capsule::connection()->transaction(function () use ($task, $leaseOwner, $leaseUntilCarbon, $now): ?Task {
            $row = Task::where('id', $task->id)
                ->where('status', 'QUEUED')
                ->where(function ($q) use ($now): void {
                    $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
                })
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return null;
            }
            $row->status = 'RUNNING';
            $row->lease_owner = $leaseOwner;
            $row->lease_expires_at = $leaseUntilCarbon;
            $row->save();
            return $row;
        });
    }

    /**
     * Mirror WorkerQueueProcessor::processQueuedTaskSync()'s exception path:
     * any thrown error from the orchestrator flips the row to FAILED so the
     * operator's UI surfaces the cause instead of leaving a phantom RUNNING.
     *
     * `singleStep: true` breaks the orchestrator's recursive tick chain
     * after one LLM turn — see OrchestratorConfig. Server-mode workers
     * leave it false so the daemon still drains each task in a single
     * recursive run; client-mode forces true so the SPA sees one step
     * per tick.
     *
     * The catch block mirrors TickPhaseRunner::handleTickFailure() so
     * client-mode failures get the same classification, notification,
     * and auto-retry treatment as server-mode failures — `error_code`
     * is no longer hardcoded to `UNKNOWN`. Context-window errors take
     * the same recovery path as in server mode.
     */
    private function runOrchestratorTickAndHandleFailure(
        Task $claimed,
        string $leaseOwner,
        float $startedAt,
    ): void {
        $orchestratorConfig = (new OrchestratorConfig())
            ->withLease($leaseOwner, $this->tickLeaseSeconds)
            ->withSingleStep(true);
        try {
            $this->orchestrator->tick($claimed->id, $orchestratorConfig);
        } catch (Throwable $e) {
            $this->handleTickFailure($claimed, $leaseOwner, $startedAt, $e);
        }
    }

    private function handleTickFailure(
        Task $claimed,
        string $leaseOwner,
        float $startedAt,
        Throwable $e,
    ): void {
        $this->logger->error('Task failed during /tick', [
            'task_id' => $claimed->id,
            'lease_owner' => $leaseOwner,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        if ($this->errorClassifier->isContextWindowError($e)) {
            // Mirror TickPhaseRunner::handleTickFailure's context-window
            // short-circuit — the recovery path drives its own LLM turn,
            // which (in singleStep mode) won't recurse, so the reaper
            // will eventually flip the row to FAILED with the original
            // error. We still notify so the operator sees the failure.
            $this->notificationService?->notifyTaskFailed(Task::find($claimed->id));
        } else {
            $errorCode = $this->errorClassifier->classifyError($e);
            $friendlyMsg = $this->errorClassifier->friendlyMessageForError($e, $errorCode);
            $updated = Task::where('id', $claimed->id)
                ->where('status', 'RUNNING')
                ->update([
                    'status'           => 'FAILED',
                    'failure_reason'   => Utf8Sanitizer::scrubString($e->getMessage()),
                    'error_code'       => $errorCode,
                    'error_message'    => Utf8Sanitizer::scrubString($friendlyMsg),
                    'lease_owner'      => null,
                    'lease_expires_at' => null,
                ]);

            if ($updated > 0) {
                $failedTask = Task::find($claimed->id);
                if ($failedTask !== null) {
                    $this->notifyFailedAndScheduleRetry($failedTask, $errorCode);
                }
            }
        }

        $this->mercure->publishForPrincipal($claimed->id, $claimed->principalOwnerId(), [
            'task_id' => $claimed->id,
            'status'  => 'FAILED',
        ]);
    }

    /**
     * After a successful tick, the lease must be cleared on terminal /
     * quiescent rows — otherwise the reaper could flip an actually-
     * finished task to FAILED on the next housekeeping pass.
     */
    private function clearLeaseIfTerminal(int $taskId): void
    {
        $policy = new TaskLifecyclePolicy();
        $fresh = Task::find($taskId);
        if ($fresh !== null && ($policy->isTerminal($fresh->status) || $policy->isQuiescent($fresh->status))) {
            $fresh->lease_owner = null;
            $fresh->lease_expires_at = null;
            $fresh->save();
        }
    }

    private function tickLostRaceResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'TICK_LOST_RACE', 'message' => 'Task could not be claimed for ticking.']],
            Response::HTTP_CONFLICT,
        );
    }

    private function tickAlreadyRunningResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'TICK_ALREADY_RUNNING', 'message' => 'Task is already being ticked.']],
            Response::HTTP_CONFLICT,
        );
    }

    private function errorForException(InvalidArgumentException $e): JsonResponse
    {
        if ($e->getMessage() === self::ERR_TASK_NOT_FOUND) {
            return $this->notFoundResponse();
        }
        return new JsonResponse(
            ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * Mirror TickPhaseRunner::notifyFailedAndScheduleRetry() so the
     * client-mode /tick path triggers the same downstream side-effects
     * (notification + auto-retry scheduling) as the server-mode daemon.
     * Both `notifyTaskFailed` and `scheduleAutoRetry` swallow their own
     * exceptions — the operator-facing `Task failed during /tick` log
     * line above is the source of truth for the failure itself.
     */
    private function notifyFailedAndScheduleRetry(Task $failedTask, string $errorCode): void
    {
        try {
            $this->notificationService?->notifyTaskFailed($failedTask);
        } catch (Throwable $e) {
            $this->logger->warning('Notification failed', [
                'task_id'   => $failedTask->id,
                'exception' => $e->getMessage(),
            ]);
        }

        try {
            $this->retryScheduler->scheduleAutoRetry($failedTask, $errorCode);
        } catch (Throwable $e) {
            $this->logger->warning('Auto-retry scheduling failed', [
                'task_id'   => $failedTask->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function notFoundResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
            Response::HTTP_NOT_FOUND,
        );
    }
}
