<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Auth\AuthService;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Console\Worker\WorkerReaper;
use Spora\Http\Exceptions\TooManyRequestsException;
use Spora\Services\DbRateLimiter;
use Spora\Services\HousekeepingLock;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Browser-driven housekeeping endpoint for client-worker mode.
 *
 * Called periodically (every 5 min) by every authed user's SharedWorker.
 * Reaps orphaned RUNNING tasks (browser or server disconnected) and
 * synchronously dispatches + ticks any due scheduled run for an agent
 * the caller can see — the browser does NOT need to drive the result
 * task; the synchronous tick completes it within this request.
 *
 * Trade-off: scheduled runs only DISPATCH while a browser is open (same
 * as before — no daemon). The synchronous tick completes the resulting
 * task without requiring the browser to stay open after dispatch.
 */
final class WorkerController
{
    /** Lock-window length — matches the 30s no-op window in the plan. */
    private const LOCK_TTL_SECONDS = 30;

    /** Rate limit (per user, per 60s) — matches `housekeeping:<userId>` plan spec. */
    private const RATE_LIMIT_MAX = 6;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly AuthService $authService,
        private readonly WorkerRuntimeMode $workerRuntimeMode,
        private readonly DbRateLimiter $rateLimiter,
        private readonly HousekeepingLock $housekeepingLock,
        private readonly WorkerReaper $workerReaper,
        private readonly ScheduledRunProcessor $scheduledProcessor,
        private readonly int $staleMinutes,
        private readonly int $tickLeaseSeconds,
    ) {}

    /**
     * POST /api/v1/worker/housekeeping
     *
     * Returns:
     *   - 404 in server mode (route stays registered; inline gate hides the surface).
     *   - 429 if the per-user rate limit is exceeded.
     *   - 204 if the DB-backed HousekeepingLock is already held by another caller.
     *   - 200 with `{reaped, scheduled_dispatched, ran_by}` on success.
     *   - 500 with a HOUSEKEEPING_FAILED envelope if anything throws.
     */
    public function housekeeping(): Response
    {
        if ($this->workerRuntimeMode !== WorkerRuntimeMode::Client) {
            return $this->notFound();
        }

        $userId = (int) $this->authService->currentUserId();
        if (!$this->rateLimiter->attempt(
            'housekeeping:' . $userId,
            self::RATE_LIMIT_MAX,
            self::RATE_LIMIT_WINDOW_SECONDS,
        )) {
            throw new TooManyRequestsException('Housekeeping rate limit exceeded.');
        }

        return $this->runHousekeeping($userId);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function runHousekeeping(int $userId): Response
    {
        // The synchronous scheduled-run tick below blocks for the full duration
        // of one LLM call. Lift PHP-FPM's per-request time limit so we don't
        // hit it mid-tick (and so the FAILED-flip exception path can finish).
        // Operators on shared hosting with tight max_execution_time should
        // keep server mode for long-running scheduled tasks.
        set_time_limit(0);

        // Shared lock: skip if another call is in flight. The 30s window is
        // long enough to complete a normal tick but short enough that a
        // crashed caller's lock won't suppress every other browser.
        if (!$this->housekeepingLock->tryAcquire(self::LOCK_TTL_SECONDS)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $buffer = new BufferedOutput();

        try {
            // Reap orphans regardless of who's calling — the reaper only
            // flips RUNNING tasks with expired leases, not caller-scoped state.
            $this->workerReaper->reapStaleTasks($buffer, $this->staleMinutes);
            $reaped = $this->extractReapedCount($buffer->fetch());

            // Synchronous dispatch-and-tick for scheduled runs. The handler
            // does the full claim → start → tick pipeline so the resulting task
            // does NOT need a browser to drive it. Trade-off: this call blocks
            // for the duration of one scheduled-run tick.
            $this->scheduledProcessor->processSynchronously(
                $buffer,
                $userId,
                $this->tickLeaseSeconds,
            );

            return $this->successResponse($reaped, $this->scheduledProcessor->lastProcessed, $userId);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        } finally {
            $this->housekeepingLock->release();
        }
    }

    private function successResponse(int $reaped, int $scheduled, int $userId): JsonResponse
    {
        return new JsonResponse([
            'reaped'               => $reaped,
            'scheduled_dispatched' => $scheduled,
            'ran_by'               => $userId,
        ]);
    }

    private function errorResponse(Throwable $e): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'HOUSEKEEPING_FAILED', 'message' => $e->getMessage()]],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }

    /**
     * Pull the "Reaped N orphan(s)" count out of the reaper's BufferedOutput.
     * Returns 0 when no orphan rows were flipped (the reaper silently returns
     * in that case without writing a line).
     */
    private function extractReapedCount(string $output): int
    {
        if (preg_match('/Reaped (\d+) orphan/', $output, $matches) === 1) {
            return (int) $matches[1];
        }
        return 0;
    }
}
