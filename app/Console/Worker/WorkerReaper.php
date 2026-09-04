<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Spora\Services\SubAgentServiceInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
        private readonly ?SubAgentServiceInterface $subAgent = null,
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
        //
        // AWAITING_SUB_AGENTS is swept alongside RUNNING because a sub-agent
        // multi-child stall (race between a concurrent child worker and the
        // parent's spawn sequence) leaves the parent parked indefinitely with
        // no live lease. Without this, the parent waits forever — see
        // dashboard-and-subagent-fixes.md Plan D.
        $orphanedIds = Task::whereIn('status', ['RUNNING', 'AWAITING_SUB_AGENTS'])
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

        // WORKER_DISCONNECTED is the reaper's marker for "the lease holder
        // vanished" — browser tab closed, housekeeping request dropped,
        // server worker SIGKILL'd. The legacy `ORPHANED` code is reserved in
        // {@see ErrorClassifier::RETRYABLE_ERROR_CODES} for compatibility with
        // any historical row that still carries it, but no code path emits
        // it anymore.
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
        $this->fireSubAgentResumeHooks($orphanedIds);
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

    /**
     * Resumes parents parked in AWAITING_SUB_AGENTS after the reaper flips
     * their child to FAILED. Without this hook, a child killed by an
     * OOM/SIGKILL race leaves the parent waiting forever — no terminal
     * transition runs through SubAgentService's resume gate.
     *
     * Handoff happens AFTER the SQL flip commits so SubAgentService sees a
     * consistent view.
     *
     * @param list<int> $orphanedIds
     */
    private function fireSubAgentResumeHooks(array $orphanedIds): void
    {
        if ($this->subAgent === null) {
            return;
        }

        foreach ($orphanedIds as $taskId) {
            $task = Task::find($taskId);
            if ($task === null || $task->parent_task_id === null) {
                continue;
            }

            $parent = Task::find((int) $task->parent_task_id);
            if ($parent === null || $parent->status !== 'AWAITING_SUB_AGENTS') {
                continue;
            }

            $parentId = (int) $task->parent_task_id;

            try {
                $this->subAgent->maybeResumeParent($taskId);
                $this->subAgent->maybeResumeParentForParent($parentId);

                $this->logger->info('reaper_resume_attempt', [
                    'task_id'        => $taskId,
                    'parent_task_id' => $parentId,
                ]);
            } catch (Throwable $e) {
                // Defensive: a sub-agent service failure must NEVER kill the
                // reaper sweep. The orphan rows are already FAILED — we'd
                // rather log and move on than nuke the whole pass.
                $this->logger->error('reaper_resume_failed', [
                    'task_id'        => $taskId,
                    'parent_task_id' => $parentId,
                    'exception'      => $e->getMessage(),
                ]);
            }
        }
    }
}
