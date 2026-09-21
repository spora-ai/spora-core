<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Console\Worker\WorkerQueueProcessor;
use Spora\Core\Paths;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;

const RETRY_T_DT = 'Y-m-d H:i:s';
const RETRY_T_PAST = '2020-01-01 00:00:00';
const RETRY_T_FUTURE = '2099-01-01 00:00:00';

/**
 * Build a WorkerQueueProcessor with mutable collaborators so each test
 * can attach its own shouldReceive() expectations. Mirrors the helper
 * shape used by the sibling parent-status suite so the two files read
 * consistently.
 *
 * Caller-supplied mocks are taken verbatim; null defaults become
 * silenced (shouldIgnoreMissing) mocks so tests that don't care
 * about a collaborator's calls don't have to wire every one up just
 * to avoid BadMethodCallException on a default strict mock.
 *
 * The default orchestrator additionally registers a `retry()` fallback
 * returning a freshly-loaded Task: the SUT calls orchestrator->retry()
 * unconditionally and Mockery's "instantiate return type" fallback
 * would otherwise explode on `final class Task`. Tests that observe
 * the retry call pass their own orchestrator mock with explicit
 * `shouldReceive('retry')` expectations, so this default never fires
 * for them — it is purely a safety net for the notification/mercure
 * tests that intentionally exercise other collaborators.
 */
function makeRetryProcessor(
    LoggerInterface $logger,
    ?OrchestratorInterface $orchestrator = null,
    ?NotificationService $notification = null,
    ?MercurePublisherInterface $mercure = null,
): WorkerQueueProcessor {
    if ($orchestrator === null) {
        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldIgnoreMissing();
        $orchestrator->shouldReceive('retry')->byDefault()
            ->andReturnUsing(static fn(int $id) => Task::find($id) ?? new Task());
    }
    if ($notification === null) {
        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldIgnoreMissing();
    }
    if ($mercure === null) {
        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldIgnoreMissing();
    }

    return new WorkerQueueProcessor(
        orchestrator: $orchestrator,
        logger: $logger,
        mercure: $mercure,
        notificationService: $notification,
        paths: new Paths(BASE_PATH),
    );
}

/**
 * Test-handler-backed logger factory matching the convention used by
 * the other suites in this directory.
 *
 * @return array{0: LoggerInterface, 1: TestHandler}
 */
function makeRetryLogger(): array
{
    $handler = new TestHandler();
    $logger  = new MonologLogger('test', [$handler]);
    return [$logger, $handler];
}

/**
 * Register a user and create an Agent with retry config. Returns
 * `[userId, agentId, principalId]` so tests can wire principal_id
 * into their task rows without an extra round-trip.
 */
function seedRetryAgent(string $email, int $maxRetries = 3): array
{
    $auth = bootAuthLayer();
    $userId = $auth->register($email, 'Password1!', 'Retry');
    $agent = Agent::create([
        'principal_id'  => createUserPrincipalPublic($userId),
        'name'          => 'RetryAgent',
        'max_steps'     => 5,
        'max_retries'   => $maxRetries,
        'is_active'     => true,
    ]);

    return [$userId, $agent->id, (int) $agent->principal_id];
}

/**
 * Insert one task row directly via the query builder (no Eloquent
 * events) and return the auto-incremented id. The in-place retry
 * self-reference (`retry_of_task_id = id`) is wired up by a follow-up
 * UPDATE because the id is only known after the INSERT returns.
 *
 * Defaults match the only shape production sets on a scheduled retry:
 * FAILED status, `retry_count = 1`, `retry_after` in the past, and
 * a non-null `user_prompt`/`step_count`. Override anything via
 * `$overrides` — pass `null` to force a column to NULL even if its
 * default would not be (e.g. `retry_after => null`).
 */
function insertRetryTask(
    int $agentId,
    int $principalId,
    int $userId,
    array $overrides = [],
): int {
    $defaults = [
        'agent_id'        => $agentId,
        'principal_id'    => $principalId,
        'trigger_user_id' => $userId,
        'status'          => 'FAILED',
        'user_prompt'     => 'retry candidate',
        'max_steps'       => 5,
        'retry_count'     => 1,
        'retry_after'     => RETRY_T_PAST,
    ];

    $row = $overrides + $defaults + [
        'created_at' => date(RETRY_T_DT),
        'updated_at' => date(RETRY_T_DT),
    ];

    return (int) Capsule::table('tasks')->insertGetId($row);
}

/**
 * Set a `retry_after` value via an UPDATE so the comparison uses the
 * exact stored representation the production SELECT compares against.
 * `Illuminate\Database\Query\Expression` (returned by `Capsule::raw`)
 * embeds the value verbatim, bypassing any PHP-side datetime string
 * normalisation that has bitten the boundary tests on MariaDB before.
 */
function setTaskRetryAfter(int $taskId, string $expr): void
{
    Capsule::table('tasks')->where('id', $taskId)->update([
        'retry_after' => Capsule::raw($expr),
    ]);
}

describe('WorkerQueueProcessor::processRetryQueue — DB-driven candidate selection', function (): void {
    it('picks up a FAILED task whose retry_after has elapsed and calls Orchestrator::retry()', function (): void {
        // Production retry chain: RetryScheduler::scheduleAutoRetry()
        // writes retry_after = now + retry_after_minutes*60. After the
        // daemon has been idle longer than that window, the task is
        // FAILED + retry_after-elapsed + retry_of_task_id=self, and
        // the worker's processRetryQueue() must hand it back to the
        // orchestrator's retry() — which is what re-queues it for
        // re-ticking.
        [$logger] = makeRetryLogger();
        [$userId, $agentId, $principalId] = seedRetryAgent('retry-elapsed@example.com');

        $taskId = insertRetryTask($agentId, $principalId, $userId, [
            'retry_after' => RETRY_T_PAST,
        ]);
        // Wire up the production self-reference now that the id is known.
        Capsule::table('tasks')->where('id', $taskId)->update(['retry_of_task_id' => $taskId]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldReceive('retry')->once()
            ->with($taskId)
            ->andReturnUsing(fn(int $id) => Task::find($id));

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });

    it('matches a task whose retry_after equals now exactly (boundary, <=)', function (): void {
        // The SQL uses `<=`, so a retry_after stamped at exactly the
        // same second the worker's $now is computed MUST still match.
        // Take a single snapshot of date() and apply it via DB::raw so
        // the stored value is bit-identical to what the worker
        // generates a moment later — avoids a string-vs-string
        // comparison skew on either engine.
        [$logger] = makeRetryLogger();
        [$userId, $agentId, $principalId] = seedRetryAgent('retry-boundary@example.com');

        $taskId = insertRetryTask($agentId, $principalId, $userId);
        $nowSnapshot = "'" . date(RETRY_T_DT) . "'";
        setTaskRetryAfter($taskId, $nowSnapshot);
        Capsule::table('tasks')->where('id', $taskId)->update(['retry_of_task_id' => $taskId]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldReceive('retry')->once()->with($taskId)
            ->andReturnUsing(static fn(int $id) => Task::find($id) ?? new Task());

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });

    it('skips a FAILED task whose retry_after is still in the future', function (): void {
        // retry_after=2099 is the production "defer forever" carve-out
        // — the candidate loop must not see it. Asserting shouldNotReceive
        // on orchestrator.retry() pins the no-op invariant without forcing
        // the test to inspect DB state after the call.
        [$logger] = makeRetryLogger();
        [$userId, $agentId, $principalId] = seedRetryAgent('retry-future@example.com');

        $taskId = insertRetryTask($agentId, $principalId, $userId, [
            'retry_after' => RETRY_T_FUTURE,
        ]);
        Capsule::table('tasks')->where('id', $taskId)->update(['retry_of_task_id' => $taskId]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('retry');

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });

    it('skips a task whose status is RUNNING (not FAILED)', function (): void {
        // RUNNING is the in-flight tick state. A mid-tick task can
        // transiently satisfy retry_after + retry_of_task_id by accident
        // (retry_count was incremented mid-flight), but the status
        // filter (`status = 'FAILED'`) is what keeps the worker from
        // double-claiming a task that the orchestrator is still
        // running.
        [$logger] = makeRetryLogger();
        [$userId, $agentId, $principalId] = seedRetryAgent('retry-running@example.com');

        $taskId = insertRetryTask($agentId, $principalId, $userId, [
            'status'      => 'RUNNING',
            'retry_after' => RETRY_T_PAST,
        ]);
        Capsule::table('tasks')->where('id', $taskId)->update(['retry_of_task_id' => $taskId]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('retry');

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });

    it('skips a task whose status is COMPLETED', function (): void {
        // Terminal success — retry_chain was already cleared by
        // continue() / canonical completion, and the row's
        // retry_after was reset to NULL in production. Belt-and-braces:
        // even if a row were somehow left FAILED→COMPLETED with stale
        // retry_after/retry_of_task_id, the status filter still skips.
        [$logger] = makeRetryLogger();
        [$userId, $agentId, $principalId] = seedRetryAgent('retry-completed@example.com');

        $taskId = insertRetryTask($agentId, $principalId, $userId, [
            'status'      => 'COMPLETED',
            'retry_after' => RETRY_T_PAST,
        ]);
        Capsule::table('tasks')->where('id', $taskId)->update(['retry_of_task_id' => $taskId]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('retry');

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });

    it('skips a task whose status is QUEUED', function (): void {
        // QUEUED is the post-retry state for Async mode: Orchestrator
        // sets QUEUED in WorkerQueueProcessor::retry(); the row is
        // therefore legitimately eligible for re-ticking but NOT for
        // another retry. The status filter must keep this loop from
        // re-claiming it.
        [$logger] = makeRetryLogger();
        [$userId, $agentId, $principalId] = seedRetryAgent('retry-queued@example.com');

        $taskId = insertRetryTask($agentId, $principalId, $userId, [
            'status'      => 'QUEUED',
            'retry_after' => RETRY_T_PAST,
        ]);
        Capsule::table('tasks')->where('id', $taskId)->update(['retry_of_task_id' => $taskId]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('retry');

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });

    it('skips a FAILED task with a NULL retry_after', function (): void {
        // NULL retry_after means the row is a fresh failure that has
        // not yet been picked up by RetryScheduler, or whose retry
        // chain was cancelled (cancelRetryChain clears retry_after).
        // The `IS NOT NULL` predicate in the SQL is non-negotiable —
        // matching a NULL would race the scheduler and produce spurious
        // duplicate retries.
        [$logger] = makeRetryLogger();
        [$userId, $agentId, $principalId] = seedRetryAgent('retry-null-after@example.com');

        $taskId = insertRetryTask($agentId, $principalId, $userId, [
            'retry_after' => null,
        ]);
        Capsule::table('tasks')->where('id', $taskId)->update(['retry_of_task_id' => $taskId]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('retry');

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });

    it('skips a FAILED task with a NULL retry_of_task_id', function (): void {
        // retry_of_task_id is the chain marker. NULL = non-retry
        // failure (the kind RetryScheduler has not yet seen), which
        // must not be re-claimed by the retry loop. Without this
        // guard, every fresh failure would auto-retry by default.
        [$logger] = makeRetryLogger();
        [$userId, $agentId, $principalId] = seedRetryAgent('retry-null-selfref@example.com');

        insertRetryTask($agentId, $principalId, $userId, [
            'retry_after'       => RETRY_T_PAST,
            'retry_of_task_id'  => null,
        ]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('retry');

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });
});

describe('WorkerQueueProcessor::processRetryQueue — collaborator fan-out', function (): void {
    it('calls Orchestrator::retry on every eligible candidate', function (): void {
        // The SELECT loop is unbounded — every task matching the
        // predicate gets handed back to Orchestrator::retry in one
        // iteration. Insert three FAILED + retry_after-elapsed rows
        // across distinct agents so the test also catches any
        // accidental deduplication by agent_id.
        [$logger] = makeRetryLogger();
        $taskIds = [];
        for ($i = 0; $i < 3; $i++) {
            [$userId, $agentId, $principalId] = seedRetryAgent("retry-fanout-{$i}@example.com");
            $taskIds[] = insertRetryTask($agentId, $principalId, $userId);
        }
        foreach ($taskIds as $id) {
            Capsule::table('tasks')->where('id', $id)->update(['retry_of_task_id' => $id]);
        }

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        foreach ($taskIds as $id) {
            $orchestrator->shouldReceive('retry')->once()->with($id)
                ->andReturnUsing(fn(int $tid) => Task::find($tid));
        }

        makeRetryProcessor($logger, $orchestrator)->processRetryQueue();
    });

    it('calls NotificationService::notifyTaskRetrying on every eligible candidate', function (): void {
        // The fan-out order is `notification → orchestrator.retry → mercure`,
        // and notifyTaskRetrying is invoked with each task's own
        // (`retryTask`, `retry_count`, `max_retries`) triple. The
        // count matches the inserted `retry_count`, so a regression
        // that re-uses an outer counter would surface here.
        [$logger] = makeRetryLogger();
        $taskIds = [];
        for ($i = 0; $i < 3; $i++) {
            [$userId, $agentId, $principalId] = seedRetryAgent("retry-notify-{$i}@example.com");
            $taskIds[] = insertRetryTask($agentId, $principalId, $userId);
        }
        foreach ($taskIds as $id) {
            Capsule::table('tasks')->where('id', $id)->update(['retry_of_task_id' => $id]);
        }

        $notification = Mockery::mock(NotificationService::class);
        foreach ($taskIds as $id) {
            $notification->shouldReceive('notifyTaskRetrying')->once()
                ->withArgs(static fn(Task $task, int $attempt, int $max): bool =>
                    $task->id === $id && $attempt === 1 && $max === 3)
                ->andReturnNull();
        }

        makeRetryProcessor($logger, orchestrator: null, notification: $notification)
            ->processRetryQueue();
    });

    it('publishes a Mercure update with status=RUNNING on every eligible candidate', function (): void {
        // The Mercure message tells principal viewers the task has
        // been claimed again. Status flips FAILED → QUEUED inside
        // orchestrator.retry(), and the worker publishes RUNNING
        // *after* the retry() call returns — the payload key+shape
        // is the contract other components depend on.
        [$logger] = makeRetryLogger();
        $taskIds = [];
        for ($i = 0; $i < 3; $i++) {
            [$userId, $agentId, $principalId] = seedRetryAgent("retry-mercure-{$i}@example.com");
            $taskIds[] = insertRetryTask($agentId, $principalId, $userId);
        }
        foreach ($taskIds as $id) {
            Capsule::table('tasks')->where('id', $id)->update(['retry_of_task_id' => $id]);
        }

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        foreach ($taskIds as $id) {
            $expectedPrincipal = (int) Capsule::table('tasks')->where('id', $id)->value('principal_id');
            $mercure->shouldReceive('publishForPrincipal')->once()
                ->with($id, $expectedPrincipal, ['task_id' => $id, 'status' => 'RUNNING'])
                ->andReturn(true);
        }

        makeRetryProcessor($logger, mercure: $mercure)->processRetryQueue();
    });

    it('handles an empty candidate set without calling any collaborator', function (): void {
        // A worker boot where no retry-elapsed rows exist is the
        // steady state — the loop must be a no-op, not a degenerate
        // call into orchestrator/notification/mercure. Empty-DB
        // boundary guards against regressions where the SELECT
        // accidentally reads zero rows as "retry everything".
        [$logger, $handler] = makeRetryLogger();
        expect((int) Capsule::table('tasks')->count())->toBe(0);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('retry');

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->shouldNotReceive('publishForPrincipal');

        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldNotReceive('notifyTaskRetrying');

        makeRetryProcessor($logger, $orchestrator, $notification, $mercure)
            ->processRetryQueue();

        expect($handler->getRecords())->toBeEmpty();
    });

    it('resolves max_retries from each task\'s agent, not from a single global value', function (): void {
        // resolveAgentMaxRetries() does one bulk SELECT against
        // agents for the candidate set's distinct agent_ids. A bug
        // that read `agents.max_retries` once and reused it would
        // match either task1's or task2's value, but not both —
        // and would silently mis-notify retry attempts belonging to
        // the other agent. Two agents, two distinct max_retries,
        // two distinct `notify` calls with the right per-task value.
        [$logger] = makeRetryLogger();
        [$userIdA, $agentIdA, $principalIdA] = seedRetryAgent('retry-agent-a@example.com', 3);
        [$userIdB, $agentIdB, $principalIdB] = seedRetryAgent('retry-agent-b@example.com', 7);

        $taskA = insertRetryTask($agentIdA, $principalIdA, $userIdA);
        $taskB = insertRetryTask($agentIdB, $principalIdB, $userIdB);
        foreach ([$taskA, $taskB] as $id) {
            Capsule::table('tasks')->where('id', $id)->update(['retry_of_task_id' => $id]);
        }

        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldReceive('notifyTaskRetrying')->once()
            ->withArgs(static fn(Task $t, int $_a, int $max): bool => $t->id === $taskA && $max === 3)
            ->andReturnNull();
        $notification->shouldReceive('notifyTaskRetrying')->once()
            ->withArgs(static fn(Task $t, int $_a, int $max): bool => $t->id === $taskB && $max === 7)
            ->andReturnNull();

        makeRetryProcessor($logger, notification: $notification)->processRetryQueue();
    });
});
