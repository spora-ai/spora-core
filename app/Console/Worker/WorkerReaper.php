<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sweeps tasks stuck in RUNNING for longer than $staleMinutes and marks them FAILED.
 *
 * These orphans are produced when a worker process is killed ungracefully (OOM, server
 * reboot, SIGKILL) before it can clean up. The reaper runs once at startup and
 * periodically in daemon mode so the system self-corrects without manual intervention.
 *
 * The timeout should exceed the worst-case LLM round-trip time for your provider to
 * avoid false positives on slow but genuinely in-progress tasks.
 */
final class WorkerReaper
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
    ) {}

    public function reapStaleTasks(OutputInterface $output, int $staleMinutes): void
    {
        if ($staleMinutes <= 0) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $now->modify(sprintf('-%d seconds', $staleMinutes * 60));

        // Lease-aware + retry-chain-aware. The reaper is shared between server-mode
        // daemon (`WorkerRunCommand --once`) and client-mode HTTP housekeeping, so the
        // SQL must work for either runtime. `retry_of_task_id IS NULL` excludes the
        // retry chain — orphaned retries are reported up to the root via
        // `RetryScheduler::scheduleRootRetry` and are never flipped directly by the
        // reaper (otherwise a reaper pass would race a pending retry and erase the
        // root's failure context).
        $orphanedIds = Task::where('status', 'RUNNING')
            ->where(function ($q) use ($now): void {
                $q->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', $now);
            })
            ->where('updated_at', '<=', $threshold)
            ->whereNull('retry_of_task_id')
            ->pluck('id')
            ->all();

        if ($orphanedIds === []) {
            return;
        }

        // WORKER_DISCONNECTED distinguishes "the lease holder vanished" (browser tab
        // closed, housekeeping request dropped, server worker SIGKILL'd) from the
        // legacy ORPHANED code emitted by other paths for explicit server crashes.
        // Same row shape regardless of runtime — the operator UI reads error_code to
        // decide whether to suggest re-opening the browser or just retrying.
        $updated = Task::whereIn('id', $orphanedIds)->update([
            'status'         => 'FAILED',
            'failure_reason' => "Task orphaned: lease expired and no progress for {$staleMinutes} minutes.",
            'error_code'     => 'WORKER_DISCONNECTED',
            'error_message'  => 'The browser driving this task disconnected. Click Retry to start a fresh attempt.',
        ]);
        if ($updated <= 0) {
            return;
        }

        $this->reportReaped($updated, $staleMinutes, $output);
        $this->notifyOrphans($orphanedIds);
    }

    private function reportReaped(int $updated, int $staleMinutes, OutputInterface $output): void
    {
        $this->logger->warning('Reaped orphaned RUNNING tasks', [
            'count' => $updated,
            'stale_minutes' => $staleMinutes,
        ]);
        $output->writeln(sprintf(
            '<comment>Reaped %d orphaned RUNNING task(s) (idle > %d min).</comment>',
            $updated,
            $staleMinutes,
        ));
    }

    /**
     * @param list<int> $orphanedIds
     */
    private function notifyOrphans(array $orphanedIds): void
    {
        $orphaned = Task::findMany($orphanedIds);
        foreach ($orphaned as $task) {
            $this->notificationService->notifyTaskOrphaned($task);
        }
    }
}
