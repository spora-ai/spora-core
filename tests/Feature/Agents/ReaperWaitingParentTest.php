<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Psr\Log\NullLogger;
use Spora\Agents\RetryScheduler;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Console\Worker\WorkerReaper;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A parent that waits on a child that still owes it an answer is not an
 * orphan.
 *
 * A parent in AWAITING_SUB_AGENTS holds no live lease while its child
 * runs: the lease is only renewed inside a tick, and the parent is not
 * ticking. The sweep's stale threshold is an hour, so a parent whose
 * child is still working — or waiting for a retry that is due later than
 * that — is reachable by the sweep, and failing it would take away the
 * answer the child is about to produce, and blame the parent for the
 * child's failure.
 *
 * The exclusion has to hold for the write as well as for the selection:
 * a child can arm its retry between the two, and the write is the one
 * that decides whether the parent is failed.
 *
 * The sweep runs in two phases, and the order matters: the children are
 * reaped and their retries are armed before the parents are judged, so a
 * parent whose child the sweep reaped in this very pass waits for that
 * child's retry instead of being failed for it.
 *
 * The other half of the rule is that the parent must not wait forever: a
 * child that has not moved for longer than the threshold is not coming
 * back, and nothing else would ever free the parent that waits for it.
 */

defined('WAITING_PARENT_TEST_PASSWORD') || define('WAITING_PARENT_TEST_PASSWORD', 'Password1!');
const WAITING_PARENT_DT = 'Y-m-d H:i:s';

/**
 * A reaper with no retry scheduler: these tests are about which rows the
 * sweep reaches, not about what it arms.
 */
function waitingParentReaper(): WorkerReaper
{
    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldIgnoreMissing();

    return new WorkerReaper(new NullLogger(), $notification);
}

/**
 * A reaper that arms the retry an agent's policy asks for, the way the
 * server worker wires it.
 */
function waitingParentReaperWithRetries(WorkerRuntimeMode $mode = WorkerRuntimeMode::Server): WorkerReaper
{
    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldIgnoreMissing();

    return new WorkerReaper(new NullLogger(), $notification, null, new RetryScheduler(), $mode);
}

/**
 * @return array{0: int, 1: int} the agent id and the owning user id
 */
function waitingParentSeedAgent(int $maxRetries = 0, int $retryAfterMinutes = 0): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('waiting-parent@example.com', WAITING_PARENT_TEST_PASSWORD, 'Waiting Parent');

    $agent = Agent::create([
        'principal_id'        => createUserPrincipalPublic($userId),
        'name'                => 'Waiting Parent Agent',
        'max_steps'           => 10,
        'is_active'           => true,
        'max_retries'         => $maxRetries,
        'retry_after_minutes' => $retryAfterMinutes,
    ]);

    return [(int) $agent->id, $userId];
}

/**
 * A parent parked on its children: AWAITING_SUB_AGENTS, no live lease,
 * quiet for longer than the threshold.
 */
function waitingParentStaleParent(int $agentId, int $userId): Task
{
    $parent = Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'AWAITING_SUB_AGENTS',
        'user_prompt'     => 'delegate this',
        'max_steps'       => 10,
        'step_count'      => 0,
    ]);

    waitingParentMakeStale($parent);

    return $parent;
}

/**
 * A child of the parent, failed and — when `$retryAfter` is given —
 * waiting for a retry that is due in an hour.
 */
function waitingParentChild(int $agentId, int $userId, int $parentId, ?string $retryAfter, string $status = 'FAILED'): Task
{
    $child = Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => $status,
        'user_prompt'     => 'do that',
        'max_steps'       => 10,
        'step_count'      => 0,
        'parent_task_id'  => $parentId,
    ]);

    if ($retryAfter !== null) {
        Capsule::table('tasks')->where('id', $child->id)->update(['retry_after' => $retryAfter]);
    }

    return $child;
}

/**
 * Age the row past the sweep's threshold: no live lease, and quiet for
 * longer than the threshold.
 *
 * `updated_at` and `lease_expires_at` are written directly: Eloquent
 * refreshes them on save(). Both columns are UTC — the reaper compares
 * them against `gmdate()`.
 */
function waitingParentMakeStale(Task $task): void
{
    Capsule::table('tasks')->where('id', $task->id)->update([
        'lease_expires_at' => gmdate(WAITING_PARENT_DT, time() - 60),
        'updated_at'       => gmdate(WAITING_PARENT_DT, time() - 61 * 60),
    ]);
}

it('does not reap a parent whose child is waiting for a retry', function (): void {
    // The retry is due in an hour — longer than the sweep's threshold, so
    // the parent goes stale while the child still waits.
    [$agentId, $userId] = waitingParentSeedAgent();
    $parent = waitingParentStaleParent($agentId, $userId);
    $child  = waitingParentChild($agentId, $userId, (int) $parent->id, gmdate(WAITING_PARENT_DT, time() + 3600));

    waitingParentReaper()->reapStaleTasks(new BufferedOutput(), 60);

    expect($parent->refresh()->status)->toBe('AWAITING_SUB_AGENTS')
        ->and($child->refresh()->status)->toBe('FAILED');
});

it('still reaps a parent whose child has no retry pending', function (): void {
    // The counterpart: the stalled-parent recovery has to survive. A child
    // that is dead for good settles the parent, and the parent itself is
    // the orphan the sweep is there for.
    [$agentId, $userId] = waitingParentSeedAgent();
    $parent = waitingParentStaleParent($agentId, $userId);
    waitingParentChild($agentId, $userId, (int) $parent->id, null);

    waitingParentReaper()->reapStaleTasks(new BufferedOutput(), 60);

    $fresh = $parent->refresh();

    expect($fresh->status)->toBe('FAILED')
        ->and((string) $fresh->error_code)->toBe('WORKER_DISCONNECTED');
});

it('does not reap a parent whose child arms its retry after the selection', function (): void {
    // The exclusion has to hold for the write, not only for the selection:
    // the child can arm its retry between the two, and a write that does
    // not carry the condition would fail the parent anyway.
    [$agentId, $userId] = waitingParentSeedAgent();
    $parent = waitingParentStaleParent($agentId, $userId);
    $child  = waitingParentChild($agentId, $userId, (int) $parent->id, null);

    $connection = Capsule::connection();
    $previousDispatcher = $connection->getEventDispatcher();
    $dispatcher = new Dispatcher();
    $connection->setEventDispatcher($dispatcher);

    $armed = false;
    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed, $child): void {
        if ($armed) {
            return;
        }

        $sql = strtolower($event->sql);
        if (!str_contains($sql, 'select') || !str_contains($sql, 'retry_of_task_id')) {
            return;
        }

        $armed = true;
        Capsule::table('tasks')->where('id', $child->id)->update([
            'retry_after' => gmdate(WAITING_PARENT_DT, time() + 3600),
        ]);
    });

    try {
        waitingParentReaper()->reapStaleTasks(new BufferedOutput(), 60);
    } finally {
        if ($previousDispatcher === null) {
            $connection->unsetEventDispatcher();
        } else {
            $connection->setEventDispatcher($previousDispatcher);
        }
    }

    expect($parent->refresh()->status)->toBe('AWAITING_SUB_AGENTS');
});

it('never reads the tasks table from inside the write that flips it', function (): void {
    // MySQL refuses an UPDATE whose predicate selects from the table the
    // UPDATE writes ("You can't specify target table 'tasks' for update in
    // FROM clause"), and the way around it — a derived table the optimizer
    // is free to merge back into that self-reference — is a promise no
    // engine keeps. The guard is a separate read, so the write itself
    // names the row and nothing else.
    [$agentId, $userId] = waitingParentSeedAgent();
    $parent = waitingParentStaleParent($agentId, $userId);
    waitingParentChild($agentId, $userId, (int) $parent->id, null);

    $writes = [];
    $connection = Capsule::connection();
    $previousDispatcher = $connection->getEventDispatcher();
    $dispatcher = new Dispatcher();
    $connection->setEventDispatcher($dispatcher);
    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$writes): void {
        if (str_starts_with(strtolower(trim($event->sql)), 'update')) {
            $writes[] = $event->sql;
        }
    });

    try {
        waitingParentReaper()->reapStaleTasks(new BufferedOutput(), 60);
    } finally {
        if ($previousDispatcher === null) {
            $connection->unsetEventDispatcher();
        } else {
            $connection->setEventDispatcher($previousDispatcher);
        }
    }

    expect($writes)->not->toBeEmpty()
        ->and(strtolower(implode(' ', $writes)))->not->toContain('select');
});

it('does not reap a parent whose child was reaped in the same sweep', function (): void {
    // The child is stale and running; the parent is stale and waiting for
    // it. The sweep reaps the child first and arms its retry — the
    // parent's answer is still on its way, so the parent must not be
    // failed for a failure that is not its own.
    [$agentId, $userId] = waitingParentSeedAgent(maxRetries: 3, retryAfterMinutes: 5);
    $parent = waitingParentStaleParent($agentId, $userId);
    $child  = waitingParentChild($agentId, $userId, (int) $parent->id, null, 'RUNNING');
    waitingParentMakeStale($child);

    waitingParentReaperWithRetries()->reapStaleTasks(new BufferedOutput(), 60);

    expect($child->refresh()->status)->toBe('FAILED')
        ->and($child->retry_after)->not->toBeNull()
        ->and($parent->refresh()->status)->toBe('AWAITING_SUB_AGENTS');
});

it('reaps a parent whose child has no retry coming', function (): void {
    // The counterpart: in client-worker mode nothing claims a scheduled
    // retry, so the child's failure is final and the parent has to settle
    // rather than wait for a retry that will never run.
    [$agentId, $userId] = waitingParentSeedAgent(maxRetries: 3, retryAfterMinutes: 5);
    $parent = waitingParentStaleParent($agentId, $userId);
    $child  = waitingParentChild($agentId, $userId, (int) $parent->id, null, 'RUNNING');
    waitingParentMakeStale($child);

    waitingParentReaperWithRetries(WorkerRuntimeMode::Client)->reapStaleTasks(new BufferedOutput(), 60);

    expect($child->refresh()->status)->toBe('FAILED')
        ->and($child->retry_after)->toBeNull()
        ->and($parent->refresh()->status)->toBe('FAILED');
});

it('does not reap a parent whose child retry has been claimed', function (): void {
    // The retry was claimed: the child is queued and its `retry_after` is
    // cleared. The parent's answer is still coming — the child is waiting
    // for a worker to pick it up — so the parent must not be failed while
    // the child is in the queue.
    [$agentId, $userId] = waitingParentSeedAgent();
    $parent = waitingParentStaleParent($agentId, $userId);
    $child  = waitingParentChild($agentId, $userId, (int) $parent->id, null, 'QUEUED');
    Capsule::table('tasks')->where('id', $child->id)->update([
        'updated_at' => gmdate(WAITING_PARENT_DT, time() - 60),
    ]);

    waitingParentReaper()->reapStaleTasks(new BufferedOutput(), 60);

    expect($parent->refresh()->status)->toBe('AWAITING_SUB_AGENTS')
        ->and($child->refresh()->status)->toBe('QUEUED');
});

it('reaps a parent whose claimed child never runs again', function (): void {
    // The counterpart: nothing will ever claim that queued child — the
    // sweep does not touch QUEUED rows — so a parent that waited for it
    // forever would never be recovered. The child's own staleness is what
    // ends the wait.
    [$agentId, $userId] = waitingParentSeedAgent();
    $parent = waitingParentStaleParent($agentId, $userId);
    $child  = waitingParentChild($agentId, $userId, (int) $parent->id, null, 'QUEUED');
    waitingParentMakeStale($child);

    waitingParentReaper()->reapStaleTasks(new BufferedOutput(), 60);

    $fresh = $parent->refresh();

    expect($fresh->status)->toBe('FAILED')
        ->and((string) $fresh->error_code)->toBe('WORKER_DISCONNECTED');
});

it('does not reap a grandparent whose child is waiting for the reaped child', function (): void {
    // A grandparent waits for the parent, and the parent waits for the
    // child the sweep just reaped. The retry is still on its way, so the
    // grandparent must not be failed for it — the parent's own clock is
    // restarted when the child's retry is armed, and that is what keeps
    // the grandparent waiting.
    [$agentId, $userId] = waitingParentSeedAgent(maxRetries: 3, retryAfterMinutes: 5);
    $grandparent = waitingParentStaleParent($agentId, $userId);
    $parent = waitingParentStaleParent($agentId, $userId);
    Capsule::table('tasks')->where('id', $parent->id)->update([
        'parent_task_id' => $grandparent->id,
    ]);

    $child = waitingParentChild($agentId, $userId, (int) $parent->id, null, 'RUNNING');
    waitingParentMakeStale($child);

    waitingParentReaperWithRetries()->reapStaleTasks(new BufferedOutput(), 60);

    expect($child->refresh()->status)->toBe('FAILED')
        ->and($child->retry_after)->not->toBeNull()
        ->and($parent->refresh()->status)->toBe('AWAITING_SUB_AGENTS')
        ->and($grandparent->refresh()->status)->toBe('AWAITING_SUB_AGENTS');
});
