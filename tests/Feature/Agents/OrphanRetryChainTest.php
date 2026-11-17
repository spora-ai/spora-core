<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\NullLogger;
use Spora\Agents\ErrorClassifier;
use Spora\Agents\RetryScheduler;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Console\Worker\WorkerReaper;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Spora\Services\TaskResourceBuilder;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The retry chain of a task whose worker vanished.
 *
 * Three things have to hold for an orphaned task to be retried, and all
 * three were broken:
 *
 * - the code the reaper writes has to be one the retry policy accepts;
 * - the reaper has to arm the retry at all — it used to only mark the row;
 * - the payload has to say whether a retry is pending, because the browser
 *   reads that key to decide whether to promise an automatic retry, and a
 *   key the payload omits arrives there as `undefined` — which is not
 *   `null`. The dashboard promised a retry for every failed task and for
 *   nothing that had one.
 */

defined('ORPHAN_TEST_PASSWORD') || define('ORPHAN_TEST_PASSWORD', 'Password1!');
const ORPHAN_DT = 'Y-m-d H:i:s';

/**
 * A reaper wired the way the server worker wires it.
 */
function orphanReaper(
    ?RetryScheduler $scheduler = null,
    WorkerRuntimeMode $mode = WorkerRuntimeMode::Server,
): WorkerReaper {
    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldIgnoreMissing();

    return new WorkerReaper(new NullLogger(), $notification, null, $scheduler, $mode);
}

/**
 * An agent with a retry policy configured.
 *
 * @return array{0: int, 1: int} the agent id and the owning user id
 */
function orphanSeedAgent(int $maxRetries = 3, int $retryAfterMinutes = 5): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('orphan@example.com', ORPHAN_TEST_PASSWORD, 'Orphan');

    $agent = Agent::create([
        'principal_id'        => createUserPrincipalPublic($userId),
        'name'                => 'Orphan Agent',
        'max_steps'           => 10,
        'is_active'           => true,
        'max_retries'         => $maxRetries,
        'retry_after_minutes' => $retryAfterMinutes,
    ]);

    return [(int) $agent->id, $userId];
}

/**
 * A task the reaper will consider: RUNNING, no live lease, quiet for
 * longer than the threshold.
 */
function orphanStaleTask(int $agentId, int $userId): Task
{
    $task = Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'the worker vanished',
        'max_steps'       => 10,
        'step_count'      => 0,
    ]);

    // `updated_at` and `lease_expires_at` are written directly: Eloquent
    // refreshes them on save(). Both columns are UTC — the reaper
    // compares them against `gmdate()` — so the fixture has to write UTC
    // too. A local stamp is an hour ahead of the threshold on a host that
    // is not UTC, and the task is then never selected.
    Capsule::table('tasks')->where('id', $task->id)->update([
        'lease_expires_at' => gmdate(ORPHAN_DT, time() - 60),
        'updated_at'       => gmdate(ORPHAN_DT, time() - 61 * 60),
    ]);

    return $task;
}

it('the code the reaper writes is one the retry policy accepts', function (): void {
    // The reaper writes WORKER_DISCONNECTED. The retryable list carried
    // ORPHANED — the code the reaper used to write before a rename that
    // did not reach the list — so nothing was written that the policy
    // would act on.
    expect(ErrorClassifier::RETRYABLE_ERROR_CODES)->toContain('WORKER_DISCONNECTED')
        ->and(RetryScheduler::RETRYABLE_ERROR_CODES)->toContain('WORKER_DISCONNECTED');
});

it('arms the retry an orphaned task is owed', function (): void {
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    orphanReaper(new RetryScheduler())->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();

    expect((string) $task->status)->toBe('FAILED')
        ->and((string) $task->error_code)->toBe('WORKER_DISCONNECTED')
        // The row the scheduler arms: the retry count, the chain marker
        // and the moment it becomes due.
        ->and((int) $task->retry_count)->toBe(1)
        ->and((int) $task->retry_of_task_id)->toBe((int) $task->id)
        ->and($task->retry_after)->not->toBeNull();
});

it('does not arm a retry in client-worker mode', function (): void {
    // Nothing claims a scheduled retry in client-worker mode, so arming
    // one there would promise a retry that never comes — the same false
    // promise the payload fix removes elsewhere.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    orphanReaper(new RetryScheduler(), WorkerRuntimeMode::Client)
        ->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();

    expect((string) $task->status)->toBe('FAILED')
        ->and((int) $task->retry_count)->toBe(0)
        ->and($task->retry_after)->toBeNull();
});

it('does not blame a browser on a server-worker install', function (): void {
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    orphanReaper(new RetryScheduler())->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();

    expect((string) $task->error_message)->not->toContain('browser')
        ->and((string) $task->error_message)->toContain('The worker running this task stopped');
});

it('blames the browser in client-worker mode', function (): void {
    // There the browser's SharedWorker really is the thing that vanished.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    orphanReaper(new RetryScheduler(), WorkerRuntimeMode::Client)
        ->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();

    expect((string) $task->error_message)->toContain('The browser driving this task disconnected');
});

it('the task payload always says whether a retry is pending', function (): void {
    // The browser decides whether to promise an automatic retry from this
    // key. A key the payload omits arrives there as `undefined`, which is
    // not `null` — so an omitted key reads as "a retry is scheduled" and
    // the dashboard promises one for every failed task.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    $resource = (new TaskResourceBuilder())->taskListResource($task);

    expect($resource)->toHaveKey('retry_after')
        ->and($resource['retry_after'])->toBeNull();
});

it('publishes a task with no retry armed as one with no retry pending', function (): void {
    // The exact condition the browser applies: `retry_after !== null`.
    // With the key present and null, the promise is not made.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    $resource = (new TaskResourceBuilder())->taskListResource($task);

    expect($resource['retry_after'] !== null)->toBeFalse();
});

it('publishes a task with a retry armed as one with a retry pending', function (): void {
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    orphanReaper(new RetryScheduler())->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();
    $resource = (new TaskResourceBuilder())->taskListResource($task);

    expect($resource['retry_after'])->not->toBeNull()
        ->and($resource['retry_after'] !== null)->toBeTrue();
});

it('leaves an already-armed retry chain alone', function (): void {
    // A row in a retry chain carries the chain marker, and the reaper
    // skips it: flipping it would race the pending retry.
    //
    // The markers are written straight to the column: the scheduler arms
    // a retry on a FAILED row, and this fixture needs a RUNNING row that
    // already carries a chain.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    Capsule::table('tasks')->where('id', $task->id)->update([
        'retry_count'      => 1,
        'retry_of_task_id' => $task->id,
        'retry_after'      => gmdate(ORPHAN_DT, time() + 300),
    ]);

    orphanReaper(new RetryScheduler())->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();

    expect((string) $task->status)->toBe('RUNNING')
        ->and((int) $task->retry_count)->toBe(1)
        ->and($task->retry_after)->not->toBeNull();
});

it('never lets a failed arming stop the reaper', function (): void {
    // The scheduler swallows its own failures and logs them — the reaper
    // relies on that contract, because a reaper that died on one task
    // would leave the rest of the orphans behind.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldReceive('notifyRetryQueued')->andThrow(new RuntimeException('the channel is down'));

    $handler = new TestHandler();
    $logger = new MonologLogger('test', [$handler]);

    $reaperNotification = Mockery::mock(NotificationService::class);
    $reaperNotification->shouldIgnoreMissing();

    $reaper = new WorkerReaper(
        $logger,
        $reaperNotification,
        null,
        new RetryScheduler($logger, $notification),
        WorkerRuntimeMode::Server,
    );

    $reaper->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();

    // The task was still reaped …
    expect((string) $task->status)->toBe('FAILED')
        ->and((string) $task->error_code)->toBe('WORKER_DISCONNECTED')
        // … and the scheduler reported why it could not arm the retry.
        ->and($handler->getRecords())->not->toBeEmpty()
        ->and($handler->getRecords()[0]->message)->toBe('Failed to schedule auto-retry');
});

it('does not spend a second retry when a second sweep overlaps the first', function (): void {
    // `worker:run --reap-only` skips the single-instance lock, so two
    // sweeps can run at once. The loser of that race must not spend an
    // attempt of the retry budget, and must not announce the same retry
    // twice.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldReceive('notifyRetryQueued')->once();
    $notification->shouldIgnoreMissing();

    $reaper = new WorkerReaper(
        new NullLogger(),
        $notification,
        null,
        new RetryScheduler(null, $notification),
        WorkerRuntimeMode::Server,
    );

    $reaper->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();
    expect((int) $task->retry_count)->toBe(1);

    // The overlap: this sweep had selected the row while it was still
    // RUNNING — before the first sweep's flip landed. Its own write finds
    // the row FAILED, so the arming must not run again.
    Capsule::table('tasks')->where('id', $task->id)->update([
        'status'           => 'RUNNING',
        'retry_of_task_id' => null,
        'updated_at'       => gmdate(ORPHAN_DT, time() - 61 * 60),
    ]);

    $reaper->reapStaleTasks(new BufferedOutput(), 60);

    $task->refresh();

    expect((int) $task->retry_count)->toBe(1)
        ->and($task->retry_after)->not->toBeNull();
});

it('does not arm a retry on a task that is no longer failed', function (): void {
    // A manual retry can land between the reaper's flip and its arming:
    // `Orchestrator::retry()` flips the row to QUEUED and clears the
    // markers. Arming on top of that leaves a QUEUED row carrying the
    // chain marker, and nobody claims it — the main queue skips rows with
    // a marker and the retry queue only looks at FAILED rows.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldReceive('notifyRetryQueued')->never();
    $notification->shouldIgnoreMissing();

    $task->status = 'QUEUED';
    $task->save();

    (new RetryScheduler(null, $notification))->scheduleAutoRetry($task, 'WORKER_DISCONNECTED');

    $task->refresh();

    expect((int) $task->retry_count)->toBe(0)
        ->and($task->retry_after)->toBeNull()
        ->and($task->retry_of_task_id)->toBeNull();
});

it('does not spend a retry on a task an operator retried while the sweep was running', function (): void {
    // The same race, seen from the reaper: the flip lands, the operator's
    // manual retry lands on top of it, and the arming that follows must
    // find nothing to arm.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldReceive('notifyRetryQueued')->never();
    $notification->shouldIgnoreMissing();

    $reaper = new WorkerReaper(
        new NullLogger(),
        $notification,
        null,
        new RetryScheduler(null, $notification),
        WorkerRuntimeMode::Server,
    );

    $connection = Capsule::connection();
    $previousDispatcher = $connection->getEventDispatcher();
    $dispatcher = new Dispatcher();
    $connection->setEventDispatcher($dispatcher);

    $retried = false;
    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$retried, $task): void {
        if ($retried) {
            return;
        }

        $sql = strtolower($event->sql);
        // The reaper's flip is the write that sets the failure reason.
        if (!str_contains($sql, 'update') || !str_contains($sql, 'failure_reason')) {
            return;
        }

        $retried = true;
        Capsule::table('tasks')->where('id', $task->id)->update([
            'status'           => 'QUEUED',
            'retry_after'      => null,
            'retry_of_task_id' => null,
        ]);
    });

    try {
        $reaper->reapStaleTasks(new BufferedOutput(), 60);
    } finally {
        if ($previousDispatcher === null) {
            $connection->unsetEventDispatcher();
        } else {
            $connection->setEventDispatcher($previousDispatcher);
        }
    }

    $task->refresh();

    expect((string) $task->status)->toBe('QUEUED')
        ->and((int) $task->retry_count)->toBe(0)
        ->and($task->retry_after)->toBeNull();
});

it('reaps nothing when another sweep flipped the row first', function (): void {
    // The same overlap, seen from the other side: the write is guarded by
    // the same predicates the selection used, so a row another sweep has
    // already flipped matches nothing — and a row that matches nothing is
    // not reaped, not armed and not announced.
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldReceive('notifyRetryQueued')->never();
    $notification->shouldReceive('notifyTaskOrphaned')->never();
    $notification->shouldIgnoreMissing();

    $reaper = new WorkerReaper(
        new NullLogger(),
        $notification,
        null,
        new RetryScheduler(null, $notification),
        WorkerRuntimeMode::Server,
    );

    // The other sweep's flip lands between this sweep's selection and its
    // write. A query listener is the only seam that reaches between the
    // two statements.
    $connection = Capsule::connection();
    $previousDispatcher = $connection->getEventDispatcher();
    $dispatcher = new Dispatcher();
    $connection->setEventDispatcher($dispatcher);

    $flipped = false;
    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$flipped, $task): void {
        if ($flipped) {
            return;
        }

        $sql = strtolower($event->sql);
        if (!str_contains($sql, 'select') || !str_contains($sql, 'retry_of_task_id')) {
            return;
        }

        $flipped = true;
        Capsule::table('tasks')->where('id', $task->id)->update([
            'status'     => 'FAILED',
            'error_code' => 'WORKER_DISCONNECTED',
        ]);
    });

    $output = new BufferedOutput();

    try {
        $reaper->reapStaleTasks($output, 60);
    } finally {
        if ($previousDispatcher === null) {
            $connection->unsetEventDispatcher();
        } else {
            $connection->setEventDispatcher($previousDispatcher);
        }
    }

    $task->refresh();

    expect((int) $task->retry_count)->toBe(0)
        ->and($task->retry_after)->toBeNull()
        ->and($output->fetch())->toBe('');
});
