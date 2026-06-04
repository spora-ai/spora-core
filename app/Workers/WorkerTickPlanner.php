<?php

declare(strict_types=1);

namespace Spora\Workers;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Pure planning helpers for the worker tick loop.
 *
 * The worker pulls these helpers to decide which tasks to process, which
 * scheduled runs to fire, and which RUNNING tasks have become orphans. The
 * helpers have no side effects and no DB / network access, which lets us
 * exercise the worker decision logic in unit tests without standing up a
 * full worker process.
 */
final class WorkerTickPlanner
{
    /** Tasks in this status are not candidates for orphan reaping. */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'CANCELLED'];

    /**
     * Decide which subset of $queuedTasks to dispatch this tick.
     *
     * - An empty input yields an empty plan.
     * - When $maxConcurrent is 0, the queue is unlimited.
     * - When $limit is 0, the per-tick cap is unlimited.
     * - $maxConcurrent is applied first, then $limit.
     *
     * @param list<array<string, mixed>> $queuedTasks
     * @return list<array<string, mixed>>
     */
    public static function planQueued(array $queuedTasks, int $maxConcurrent, int $limit): array
    {
        if ($queuedTasks === []) {
            return [];
        }

        $afterMax = $maxConcurrent > 0
            ? array_slice($queuedTasks, 0, $maxConcurrent)
            : $queuedTasks;

        if ($limit <= 0) {
            return $afterMax;
        }
        return array_slice($afterMax, 0, $limit);
    }

    /**
     * True if the given task is RUNNING and has not been updated in
     * $maxAgeSeconds or more. Terminal tasks are never orphans.
     *
     * The task array is expected to contain:
     *   - status: string
     *   - updated_at: string (Y-m-d H:i:s) or DateTimeInterface
     *
     * A $maxAgeSeconds of 0 disables the check and always returns false.
     *
     * @param array<string, mixed> $task
     */
    public static function isOrphan(array $task, int $maxAgeSeconds, ?DateTimeImmutable $now = null): bool
    {
        if ($maxAgeSeconds <= 0) {
            return false;
        }
        $status = (string) ($task['status'] ?? '');
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            return false;
        }
        if ($status !== 'RUNNING') {
            return false;
        }

        $updatedAt = $task['updated_at'] ?? null;
        if ($updatedAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $updated = $updatedAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($updatedAt)
                : new DateTimeImmutable((string) $updatedAt, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return false;
        }

        return ($now->getTimestamp() - $updated->getTimestamp()) >= $maxAgeSeconds;
    }

    /**
     * True if the schedule is due to fire at $now.
     *
     * Rules:
     *   - If $lastScheduled is null, the schedule is considered due (never run).
     *   - Empty / invalid cron expressions are treated as "due when never run";
     *     callers are expected to have validated the cron at config time.
     *   - Otherwise the schedule is due if the previous run was strictly
     *     before the most recent cron occurrence.
     */
    public static function isScheduledDue(
        ?DateTimeImmutable $lastScheduled,
        string $cronExpr,
        ?DateTimeImmutable $now = null,
    ): bool {
        if ($lastScheduled === null) {
            return true;
        }

        $cronExpr = trim($cronExpr);
        if ($cronExpr === '' || !CronExpression::isValidExpression($cronExpr)) {
            return false;
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $cron = new CronExpression($cronExpr);
            $previousDue = $cron->getPreviousRunDate($now);
        } catch (Throwable) {
            return false;
        }

        return $lastScheduled < $previousDue;
    }
}
