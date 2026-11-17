<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Psr\Log\NullLogger;
use Spora\Console\Worker\WorkerReaper;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A parent that waits on a child's retry is not an orphan.
 *
 * A parent in AWAITING_SUB_AGENTS holds no live lease while its child
 * runs: the lease is only renewed inside a tick, and the parent is not
 * ticking. The sweep's stale threshold is an hour, so a parent whose
 * child is waiting for a retry that is due later than that is reachable
 * by the sweep — and failing it would take away the answer the retry is
 * about to produce, and blame the parent for the child's failure.
 *
 * The exclusion has to hold for the write as well as for the selection:
 * a child can arm its retry between the two, and the write is the one
 * that decides whether the parent is failed.
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
 * @return array{0: int, 1: int} the agent id and the owning user id
 */
function waitingParentSeedAgent(): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('waiting-parent@example.com', WAITING_PARENT_TEST_PASSWORD, 'Waiting Parent');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Waiting Parent Agent',
        'max_steps'    => 10,
        'is_active'    => true,
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

    // `updated_at` and `lease_expires_at` are written directly: Eloquent
    // refreshes them on save(). Both columns are UTC — the reaper compares
    // them against `gmdate()`.
    Capsule::table('tasks')->where('id', $parent->id)->update([
        'lease_expires_at' => gmdate(WAITING_PARENT_DT, time() - 60),
        'updated_at'       => gmdate(WAITING_PARENT_DT, time() - 61 * 60),
    ]);

    return $parent;
}

/**
 * A child of the parent, failed and — when `$retryAfter` is given —
 * waiting for a retry that is due in an hour.
 */
function waitingParentChild(int $agentId, int $userId, int $parentId, ?string $retryAfter): Task
{
    $child = Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'FAILED',
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
