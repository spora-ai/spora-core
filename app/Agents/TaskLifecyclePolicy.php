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
 *                  ABORTED, PENDING_APPROVAL, AWAITING_SUB_AGENTS.
 *   - Active     : the worker / orchestrator is currently driving the task.
 *                  RUNNING, QUEUED.
 */
final class TaskLifecyclePolicy
{
    /** @var list<string> */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'CANCELLED'];

    /** @var list<string> */
    public const QUIESCENT_STATUSES = ['ABORTED', 'PENDING_APPROVAL', 'AWAITING_SUB_AGENTS'];

    /** @var list<string> */
    public const ACTIVE_STATUSES = ['RUNNING', 'QUEUED'];

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

    /**
     * True when `abortTask()` may flip the status to `ABORTED`.
     * `PENDING_APPROVAL` is rejected with 409 because that state already
     * offers a dedicated approve/reject affordance — overloading it with
     * an "abort" would change user-facing semantics.
     */
    public function canAbortFrom(string $status): bool
    {
        return in_array($status, ['RUNNING', 'AWAITING_SUB_AGENTS'], true);
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
    public function incomingSourceErrorMessage(string $status): string
    {
        return 'Can only continue completed, failed, aborted, or running tasks.';
    }
}
