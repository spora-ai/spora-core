<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Task;

/**
 * Whether a sub-agent's child task has settled for good.
 *
 * A child whose retry is pending has NOT settled. The retry runs the child
 * again, and the parent's answer has to wait for that outcome — settling on
 * the failure that armed the retry would import a failure the system is
 * already about to retry, and the retry's result would then land on a parent
 * that is no longer waiting.
 *
 * The rule lives here rather than in {@see SubAgentService}: that class is
 * at the method budget a single class may hold, and the rule is one the
 * parent-resume gate and the sibling gate have to agree on.
 */
final class ChildSettlement
{
    /**
     * Whether the child has settled: a terminal state, and not waiting on a
     * retry of its own.
     *
     * The scheduler arms the retry on the failed row itself, so
     * `retry_after` is the marker. It is cleared when the retry is claimed
     * and when the operator cancels the chain — both of which settle the
     * child again.
     */
    public static function isSettled(Task $child): bool
    {
        return in_array($child->status, ['COMPLETED', 'FAILED'], true)
            && $child->retry_after === null;
    }
}
