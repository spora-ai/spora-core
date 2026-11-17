<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Spora\Agents\RetryScheduler;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Spora\Services\SubAgentServiceInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Sweeps tasks whose worker vanished and marks them FAILED.
 *
 * Two statuses are swept: RUNNING, and AWAITING_SUB_AGENTS — a sub-agent
 * multi-child stall leaves the parent parked with no live lease, and
 * without this it would wait forever. In server-worker mode the reaper
 * also arms the retry the agent's policy asks for, because a task whose
 * worker vanished is exactly the case an auto-retry policy exists for.
 *
 * The sweep runs in two phases. The RUNNING rows are reaped and their
 * retries are armed before the AWAITING_SUB_AGENTS rows are judged: a
 * parent whose child was reaped in this very sweep waits for that
 * child's retry, and the guard can only see that retry once it is armed.
 *
 * A parent that waits on a child's pending retry is not swept: the
 * parent's lease is not renewed while it waits, so the sweep would reach
 * it, and failing it would take away the answer the retry is about to
 * produce.
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
        private readonly ?RetryScheduler $retryScheduler = null,
        private readonly WorkerRuntimeMode $workerRuntimeMode = WorkerRuntimeMode::Server,
    ) {}

    public function reapStaleTasks(OutputInterface $output, int $staleMinutes): void
    {
        if ($staleMinutes <= 0) {
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $now->modify(sprintf('-%d seconds', $staleMinutes * 60));

        $children = $this->selectStaleCandidates('RUNNING', $now, $threshold);
        $parents = $this->selectStaleCandidates('AWAITING_SUB_AGENTS', $now, $threshold);

        if ($children === [] && $parents === []) {
            return;
        }

        // The children are reaped and their retries are armed before the
        // parents are judged: a parent whose child was reaped in this very
        // sweep waits for that child's retry, and the guard that keeps such
        // a parent out of the sweep can only see the retry once it is
        // armed.
        //
        // The flip carries the predicates that decide a row's fate: the
        // status it was selected in and the chain marker. Two reapers may
        // sweep at once — `--reap-only` deliberately skips the
        // single-instance lock — and the loser of that race must not
        // flip a row the winner already flipped: that would burn an
        // attempt of the retry budget and send a second notification. The
        // rows this write actually matched are the ones that were reaped;
        // the rest belong to the sweep that won.
        $reapedChildren = $this->markReaped($children, $staleMinutes);
        $this->armRetries($reapedChildren);

        $reapedParents = $this->markReaped($parents, $staleMinutes);
        $this->armRetries($reapedParents);

        $reaped = array_merge($reapedChildren, $reapedParents);

        if ($reaped === []) {
            return;
        }

        $this->reportReaped(count($reaped), $staleMinutes, $output);
        $this->notifyOrphans($reaped);
        $this->fireSubAgentResumeHooks($reaped);
    }

    /**
     * The tasks this sweep considers orphaned.
     *
     * Lease-aware + retry-chain-aware. The reaper is shared between server-mode
     * daemon (`WorkerRunCommand --once`) and client-mode HTTP housekeeping, so the
     * SQL must work for either runtime. `retry_of_task_id IS NULL` excludes the
     * retry chain: a task carrying the marker is a task with a retry pending or
     * in flight, and flipping it would race that retry and erase the root's
     * failure context.
     *
     * One status per call: the sweep judges the RUNNING rows and the
     * AWAITING_SUB_AGENTS rows separately, because a parent is only judged
     * once its children's retries are armed.
     *
     * AWAITING_SUB_AGENTS is swept alongside RUNNING because a sub-agent
     * multi-child stall (race between a concurrent child worker and the
     * parent's spawn sequence) leaves the parent parked indefinitely with
     * no live lease. Without this, the parent waits forever — see
     * dashboard-and-subagent-fixes.md Plan D.
     *
     * @return list<int>
     */
    private function selectStaleCandidates(string $status, DateTimeImmutable $now, DateTimeImmutable $threshold): array
    {
        return Task::where('status', $status)
            ->where(function ($q) use ($now): void {
                $q->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', $now);
            })
            ->where('updated_at', '<=', $threshold)
            ->whereNull('retry_of_task_id')
            ->pluck('id')
            ->all();
    }

    /**
     * Flip the candidates to FAILED and keep the rows the write matched.
     *
     * One guarded write per row: the predicate is what the database
     * evaluates at write time, so a row another sweep has already flipped
     * matches nothing and drops out of the result. A single bulk write
     * would report only how many rows it changed, not which ones — and
     * the difference is the whole point: the reaped set decides which
     * tasks get a retry, a notification and a resume hook.
     *
     * @param list<int> $candidateIds
     * @return list<int>
     */
    private function markReaped(array $candidateIds, int $staleMinutes): array
    {
        $reaped = [];

        foreach ($candidateIds as $candidateId) {
            if ($this->childWaitsOnPendingRetry($candidateId)) {
                continue;
            }

            $affected = $this->flipToFailed($candidateId, ['RUNNING', 'AWAITING_SUB_AGENTS'], $staleMinutes);

            if ($affected > 0) {
                $reaped[] = $candidateId;
            }
        }

        return $reaped;
    }

    /**
     * Keep a parent that waits on a child's pending retry out of the sweep.
     *
     * A parent in AWAITING_SUB_AGENTS holds no live lease while its child
     * waits: the lease is only renewed inside a tick, and the parent is not
     * ticking. So the sweep reaches a parent whose child is waiting for a
     * retry — and failing that parent would take away the answer the retry
     * is going to produce, and would blame the parent for a failure that is
     * not its own.
     *
     * The child lookup is a read of its own: the flip's predicate must not
     * read the table it writes, because MySQL refuses an UPDATE that
     * selects from its own target table ("You can't specify target table
     * 'tasks' for update in FROM clause"), and the way around it — a
     * derived table the optimizer is free to merge back into that
     * self-reference — is a promise no engine keeps.
     */
    private function childWaitsOnPendingRetry(int $parentId): bool
    {
        return Task::where('parent_task_id', $parentId)
            ->whereNotNull('retry_after')
            ->exists();
    }

    /**
     * The one write the sweep makes.
     *
     * It names the row, the statuses the row may be in and the chain
     * marker, and nothing else: it never selects from the table it
     * writes.
     *
     * @param list<string> $statuses
     */
    private function flipToFailed(int $candidateId, array $statuses, int $staleMinutes): int
    {
        return Task::where('id', $candidateId)
            ->whereIn('status', $statuses)
            ->whereNull('retry_of_task_id')
            ->update([
                'status'         => 'FAILED',
                'failure_reason' => "Task orphaned: lease expired and no progress for {$staleMinutes} minutes.",
                'error_code'     => 'WORKER_DISCONNECTED',
                'error_message'  => $this->disconnectMessage(),
            ]);
    }

    /**
     * What the operator is told when the task's worker vanished.
     *
     * The reaper runs in both runtime modes, and the thing that vanished is
     * the browser's SharedWorker in one and the server worker in the other.
     * Naming the browser on a server-worker install describes an actor that
     * is not there.
     */
    private function disconnectMessage(): string
    {
        return $this->workerRuntimeMode === WorkerRuntimeMode::Client
            ? 'The browser driving this task disconnected. Click Retry to start a fresh attempt.'
            : 'The worker running this task stopped before it finished. Click Retry to start a fresh attempt.';
    }

    /**
     * Arm the retry the operator's policy asks for.
     *
     * A task whose worker vanished is exactly the case an auto-retry policy
     * exists for: the run did not fail because the agent misbehaved. The
     * row is FAILED now, and the scheduler puts it back in the queue after
     * the agent's configured delay.
     *
     * Only the server worker claims scheduled retries — in client-worker mode
     * nothing would ever pick the row up, so arming it there would promise a
     * retry that never comes.
     *
     * Idempotent: a task that already carries a pending retry has been armed
     * already, and arming it a second time would burn an attempt of the
     * budget and send a second notification for the same retry.
     *
     * @param list<int> $orphanedIds
     */
    private function armRetries(array $orphanedIds): void
    {
        if ($orphanedIds === []) {
            return;
        }

        if ($this->retryScheduler === null || $this->workerRuntimeMode === WorkerRuntimeMode::Client) {
            return;
        }

        foreach (Task::findMany($orphanedIds) as $task) {
            if ($task->retry_after !== null) {
                continue;
            }

            $this->retryScheduler->scheduleAutoRetry($task, 'WORKER_DISCONNECTED');
        }
    }

    private function reportReaped(int $updated, int $staleMinutes, OutputInterface $output): void
    {
        $this->logger->warning('Reaped orphaned tasks', [
            'count' => $updated,
            'stale_minutes' => $staleMinutes,
        ]);
        $output->writeln(sprintf(
            '<comment>Reaped %d orphaned task(s) (idle > %d min).</comment>',
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
     * OOM/SIGKILL race leaves the parent waiting forever — the reaper's
     * flip is not a transition the parent's own hook sees.
     *
     * A child whose retry the reaper armed is not settled yet, so the
     * resume gate refuses it and the parent waits for the retry instead.
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
