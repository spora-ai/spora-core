<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Spora\Workers\WorkerTickPlanner;
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
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
    ) {}

    public function reapStaleTasks(OutputInterface $output, int $staleMinutes): void
    {
        if ($staleMinutes <= 0) {
            return;
        }

        $maxAgeSeconds = $staleMinutes * 60;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $orphanedIds = $this->findOrphanedTaskIds($maxAgeSeconds, $now);
        if ($orphanedIds === []) {
            return;
        }

        $updated = $this->markOrphansFailed($orphanedIds, $staleMinutes);
        if ($updated <= 0) {
            return;
        }

        $this->reportReaped($updated, $staleMinutes, $output);
        $this->notifyOrphans($orphanedIds);
    }

    /**
     * @return list<int>
     */
    private function findOrphanedTaskIds(int $maxAgeSeconds, DateTimeImmutable $now): array
    {
        $candidates = Task::where('status', 'RUNNING')
            ->get(['id', 'status', 'updated_at']);

        $orphanedIds = [];
        foreach ($candidates as $candidate) {
            $payload = [
                'id'         => $candidate->id,
                'status'     => $candidate->status,
                'updated_at' => $candidate->updated_at !== null
                    ? $candidate->updated_at->format(self::DB_DATETIME_FORMAT)
                    : null,
            ];
            if (WorkerTickPlanner::isOrphan($payload, $maxAgeSeconds, $now)) {
                $orphanedIds[] = $candidate->id;
            }
        }
        return $orphanedIds;
    }

    /**
     * @param list<int> $orphanedIds
     */
    private function markOrphansFailed(array $orphanedIds, int $staleMinutes): int
    {
        return Task::whereIn('id', $orphanedIds)->update([
            'status'         => 'FAILED',
            'failure_reason' => sprintf(
                'Task orphaned: still RUNNING after %d minutes — worker process likely crashed or was restarted.',
                $staleMinutes,
            ),
            'error_code'    => 'ORPHANED',
            'error_message' => 'The task was interrupted. Click Retry to start a fresh attempt.',
        ]);
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
