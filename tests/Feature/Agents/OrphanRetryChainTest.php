<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
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
    [$agentId, $userId] = orphanSeedAgent();
    $task = orphanStaleTask($agentId, $userId);

    (new RetryScheduler())->scheduleAutoRetry($task, 'RATE_LIMIT');

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
