<?php

declare(strict_types=1);

namespace Spora\Agents;

/**
 * Narrow status-transition policy used by the abort/resume path. Extracted
 * from inline status literals so the rules have a single source of truth
 * that future migrations (e.g. replacing every status literal in `app/`)
 * can adopt incrementally without rewriting the abort flow.
 *
 * Status taxonomy:
 *   - Terminal   : no further transitions. COMPLETED, FAILED, CANCELLED.
 *   - Quiescent  : requires user action before the worker re-engages.
 *                  ABORTED, PENDING_APPROVAL, AWAITING_INPUT, AWAITING_SUB_AGENTS.
 *   - Active     : the worker / orchestrator is currently driving the task.
 *                  RUNNING, QUEUED.
 */
final class TaskLifecyclePolicy
{
    /** @var list<string> */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'CANCELLED'];

    /** @var list<string> */
    public const QUIESCENT_STATUSES = ['ABORTED', 'PENDING_APPROVAL', 'AWAITING_INPUT', 'AWAITING_SUB_AGENTS'];

    /** @var list<string> */
    public const ACTIVE_STATUSES = ['RUNNING', 'QUEUED'];

    /**
     * The statuses a run is still in.
     *
     * `RUNNING` is the worker driving the task. `AWAITING_SUB_AGENTS` is a
     * parent whose children are running: quiescent in the sense that the
     * worker does not drive it, but the run is **not over** — so a limit or
     * a failure that has to end the task has to reach it there too.
     *
     * @var list<string>
     */
    public const IN_PROGRESS_STATUSES = ['RUNNING', 'AWAITING_SUB_AGENTS'];

    /**
     * True when the status cannot transition any further (`COMPLETED`,
     * `FAILED`, `CANCELLED`). Worker cleanup treats these as "no orphan
     * candidates".
     */
    public function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * True when the task is waiting on a user (or system) action before
     * the next tick. The frontend detail poller skips these to avoid
     * pointless fetches.
     */
    public function isQuiescent(string $status): bool
    {
        return in_array($status, self::QUIESCENT_STATUSES, true);
    }

    public function isInProgress(string $status): bool
    {
        return in_array($status, self::IN_PROGRESS_STATUSES, true);
    }

    /**
     * True when `abortTask()` may flip the status to `ABORTED`.
     * `PENDING_APPROVAL` and `AWAITING_INPUT` are rejected with 409 because
     * those states already offer dedicated approve/reject/answer affordances
     * — overloading them with an "abort" would change user-facing semantics.
     */
    public function canAbortFrom(string $status): bool
    {
        return $this->isInProgress($status);
    }

    /**
     * True when `Orchestrator::continue()` may transition from the given
     * status. `RUNNING` sources auto-flip to `ABORTED` then resume; the
     * other source states continue as before.
     */
    public function canContinueFrom(string $status): bool
    {
        return in_array($status, ['COMPLETED', 'FAILED', 'ABORTED', 'RUNNING'], true);
    }

    /**
     * Canonical error message for callers that already know the source
     * status but want to keep the policy decision in one place. {@see
     * Orchestrator::continue()} uses this so the wording stays aligned with
     * {@see canContinueFrom()} when the accepted-source list changes.
     */
    public function incomingSourceErrorMessage(): string
    {
        return 'Can only continue completed, failed, aborted, or running tasks.';
    }
}
