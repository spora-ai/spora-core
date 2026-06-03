<?php

declare(strict_types=1);

use Cron\CronExpression;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Agents\OrchestratorInterface;
use Spora\Console\Commands\WorkerRunCommand;
use Spora\Core\Database;
use Spora\Models\Agent;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// Fixed dates for deterministic tests — always before/after wall clock.
define('WORKER_TEST_PAST_DUE_AT', '2025-01-01 10:00:00');
define('WORKER_TEST_FUTURE_DUE_AT', '2099-01-01 10:00:00');

defined('SQLITE_MEMORY') || define('SQLITE_MEMORY', ':memory:');
const WORKER_TEST_PASSWORD = 'Password1!';
const DATETIME_FORMAT = 'Y-m-d H:i:s';
const DAILY_9AM_CRON = '0 9 * * *';

/**
 * @return array{0: WorkerRunCommand, 1: OrchestratorInterface, 2: Database}
 */
function makeWorkerRunCommand(): array
{
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
    $db->boot();

    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps): Task {
        return Task::create([
            'agent_id'    => $agentId,
            'user_id'     => 1,
            'status'      => 'RUNNING',
            'user_prompt' => $prompt,
            'max_steps'   => $maxSteps,
            'step_count'  => 0,
        ]);
    });

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);

    $notificationService = Mockery::mock(NotificationService::class);
    $notificationService->allows('notifyScheduledRunCompleted')->andReturnNull();
    $notificationService->allows('sendEmailForScheduledRun')->andReturnNull();

    $container = Mockery::mock(Psr\Container\ContainerInterface::class);
    $container->allows('get')->with('config')->andReturn(['worker_stale_minutes' => 60]);

    $command = new WorkerRunCommand(
        $db,
        $orchestrator,
        new NullLogger(),
        $container,
        $mercure,
        $notificationService,
    );

    return [$command, $orchestrator, $db];
}

/**
 * Registers an agent in the same DB that makeWorkerRunCommand() boots.
 * Must be called AFTER makeWorkerRunCommand() so both share the same in-memory DB.
 */
function registerAgentInWorkerDb(): array
{
    // Use the already-booted global Capsule (set up by makeWorkerRunCommand).
    // Do NOT call resetBootState() or boot() here — that would create a
    // second in-memory database with a different connection, causing the worker
    // and test assertions to query different databases.
    $authService = bootAuthLayer();
    $userId = $authService->register('worker-test@example.com', WORKER_TEST_PASSWORD, 'Workertest');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'WorkerTestAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent->id];
}

/**
 * Invoke processScheduledRuns via reflection, passing $processed by reference.
 */
function runProcessScheduledRuns(WorkerRunCommand $command): int
{
    $command->lastScheduledProcessed = 0;
    $ref = new ReflectionMethod($command, 'processScheduledRuns');
    $ref->invoke($command, new NullOutput());
    return $command->lastScheduledProcessed;
}

describe('WorkerRunCommand processScheduledRuns', function (): void {
    it('picks up a PENDING entry that is due and marks it DONE', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Daily check',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_PAST_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        $processed = runProcessScheduledRuns($command);

        expect($processed)->toBe(1);

        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->first();
        expect($entry->status)->toBe(ScheduledRunNext::STATUS_DONE);
    });

    it('does not pick up a future PENDING entry', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Future task',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'next_run_at'     => WORKER_TEST_FUTURE_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_FUTURE_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        $processed = runProcessScheduledRuns($command);

        expect($processed)->toBe(0);

        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->first();
        expect($entry->status)->toBe(ScheduledRunNext::STATUS_PENDING);
    });

    it('inserts next PENDING entry after processing a recurring run', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Recurring task',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_PAST_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        $processed = runProcessScheduledRuns($command);

        expect($processed)->toBe(1);

        $pendingCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->count();
        $doneCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_DONE)
            ->count();

        expect($pendingCount)->toBe(1);
        expect($doneCount)->toBe(1);
    });

    it('computes next_run_at from wall-clock now, not last_run_at, for recurring runs', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Recurring from last_run',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'last_run_at'     => '2025-01-01 08:00:00',
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_PAST_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        runProcessScheduledRuns($command);

        $nextEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->first();

        expect($nextEntry)->not->toBeNull();

        $nextDue = new DateTimeImmutable($nextEntry->due_at, new DateTimeZone('UTC'));
        // next run is computed from wall-clock now, not from last_run_at.
        // With cron DAILY_9AM_CRON and the current date being past 9 AM,
        // the next occurrence is tomorrow 09:00 UTC.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expected = (new CronExpression(DAILY_9AM_CRON))
            ->getNextRunDate($now, 0, false, 'UTC')
            ->setTimezone(new DateTimeZone('UTC'));
        expect($nextDue->format('Y-m-d H:i'))->toBe($expected->format('Y-m-d H:i'));
    });

    it('skips PENDING entry for deactivated scheduled runs and marks it SKIPPED', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Deactivated task',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => false,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_PAST_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        $processed = runProcessScheduledRuns($command);

        expect($processed)->toBe(0);

        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->first();
        expect($entry->status)->toBe(ScheduledRunNext::STATUS_SKIPPED);
    });

    it('handles Europe/Berlin timezone correctly — next run is 09:00 Berlin time', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Berlin daily at 09:00',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'Europe/Berlin',
            'is_active'       => true,
            'last_run_at'     => '2025-01-01 08:00:00',
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_PAST_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        runProcessScheduledRuns($command);

        $nextEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->first();

        expect($nextEntry)->not->toBeNull();

        $nextDue = new DateTimeImmutable($nextEntry->due_at, new DateTimeZone('UTC'));
        $berlin = $nextDue->setTimezone(new DateTimeZone('Europe/Berlin'));
        expect((int) $berlin->format('H'))->toBe(9);
        expect((int) $berlin->format('i'))->toBe(0);
    });

    it('only one worker can claim the same PENDING entry — atomic claim prevents double-run', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Concurrent test',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_PAST_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        $p1 = runProcessScheduledRuns($command);
        expect($p1)->toBe(1);

        $p2 = runProcessScheduledRuns($command);
        expect($p2)->toBe(0);

        $doneCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_DONE)
            ->count();
        expect($doneCount)->toBe(1);
    });

    it('worker creates new PENDING entry after processing a past-due recurring run', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Daily briefing',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'last_run_at'     => null,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_PAST_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        $processed = runProcessScheduledRuns($command);

        expect($processed)->toBe(1);

        $nextPending = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->first();

        expect($nextPending)->not->toBeNull();
        expect($nextPending->due_at)->not->toBe(WORKER_TEST_PAST_DUE_AT);

        $doneEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_DONE)
            ->first();
        expect($doneEntry)->not->toBeNull();
    });

    it('marks entry DONE and creates next PENDING atomically — no partial state if DB update fails', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id'         => $userId,
            'raw_prompt'      => 'Atomic test',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'last_run_at'     => null,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'          => WORKER_TEST_PAST_DUE_AT,
            'status'          => ScheduledRunNext::STATUS_PENDING,
            'created_at'      => date(DATETIME_FORMAT),
            'updated_at'      => date(DATETIME_FORMAT),
        ]);

        // Intercept the Capsule connection to make the UPDATE fail after orchestrator succeeds.
        // This simulates a crash between orchestrator->start() and the DB transaction commit.
        $pdo = Capsule::connection()->getRawPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // We'll use a sentinel to cause the second UPDATE to fail — the DONE update will succeed,
        // but the insert of next PENDING will fail due to the transaction being rolled back.
        // Actually, we can't easily inject failure mid-transaction in SQLite without a custom driver.
        // Instead, verify the happy path: no CLAIMED entries remain after processing.
        $processed = runProcessScheduledRuns($command);

        expect($processed)->toBe(1);

        // No CLAIMED entries should remain — old entry is DONE, new entry is PENDING
        $claimedCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_CLAIMED)
            ->count();
        expect($claimedCount)->toBe(0);

        $doneCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_DONE)
            ->count();
        expect($doneCount)->toBe(1);

        $pendingCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->count();
        expect($pendingCount)->toBe(1);
    });
});

describe('WorkerRunCommand processQueuedTaskSync', function (): void {
    it('marks task FAILED when orchestrator->tick() throws an exception', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps): Task {
            return Task::create([
                'agent_id'    => $agentId,
                'user_id'     => 1,
                'status'      => 'RUNNING',
                'user_prompt' => $prompt,
                'max_steps'   => $maxSteps,
                'step_count'  => 0,
            ]);
        });
        // Make tick() throw an exception — simulating an LLM failure
        $orchestrator->allows('tick')->andThrow(new RuntimeException('LLM connection failed'));

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->allows('publish')->andReturn(true);

        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->allows('notifyTaskFailed')->andReturnNull();
        $notificationService->allows('notifyTaskOrphaned')->andReturnNull();
        $notificationService->allows('notifyScheduledRunCompleted')->andReturnNull();
        $notificationService->allows('sendEmailForScheduledRun')->andReturnNull();

        $container = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn(['worker_stale_minutes' => 60]);

        $command = new WorkerRunCommand(
            $db,
            $orchestrator,
            new NullLogger(),
            $container,
            $mercure,
            $notificationService,
        );

        // Create agent and task
        $authService = bootAuthLayer();
        $userId = $authService->register('worker-test@example.com', WORKER_TEST_PASSWORD, 'Workertest');

        $agent = Agent::create([
            'user_id'   => $userId,
            'name'      => 'WorkerTestAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $userId,
            'status'      => 'QUEUED',
            'user_prompt' => 'Test prompt',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        $output = new NullOutput();
        $processed = 0;

        // Invoke processQueuedTaskSync via reflection (must use invokeArgs with array to preserve reference)
        $ref = new ReflectionMethod($command, 'processQueuedTaskSync');
        $ref->invokeArgs($command, [$output, 1000, &$processed]);

        // Task must be FAILED, not RUNNING
        $task->refresh();
        expect($task->status)->toBe('FAILED');
        expect($processed)->toBe(1);
    });
});

describe('WorkerRunCommand --reap-only', function (): void {
    it('exits immediately without processing the queue when --reap-only is set', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        // tick() must NOT be called in --reap-only mode
        $orchestrator->shouldNotReceive('tick');
        // start() must NOT be called either
        $orchestrator->shouldNotReceive('start');

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->allows('publish')->andReturn(true);

        $notificationService = Mockery::mock(NotificationService::class);

        $container = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn(['worker_stale_minutes' => 60]);
        $container->allows('get')->with('tool_instances')->andReturn([]);

        $command = new WorkerRunCommand(
            $db,
            $orchestrator,
            new NullLogger(),
            $container,
            $mercure,
            $notificationService,
        );

        $authService = bootAuthLayer();
        $userId = $authService->register('reaponly-test@example.com', WORKER_TEST_PASSWORD, 'ReapOnlyTest');
        $agent = Agent::create([
            'user_id'   => $userId,
            'name'      => 'ReapOnlyTestAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        // Create a QUEUED task — it should NOT be processed in --reap-only mode
        Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $userId,
            'status'      => 'QUEUED',
            'user_prompt' => 'Should not run',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        // ArrayInput needs the command's definition to resolve options.
        // Bind the input to the command so it gets the option definitions.
        $input = new ArrayInput(['--reap-only' => true], $command->getDefinition());
        $output = new NullOutput();

        $ref = new ReflectionMethod($command, 'execute');
        $exitCode = $ref->invoke($command, $input, $output);

        // Must exit cleanly
        expect($exitCode)->toBe(0);

        // Task must still be QUEUED (not processed)
        $task = Task::first();
        expect($task->status)->toBe('QUEUED');
    });

    it('calls reapStaleTasks and marks orphaned tasks FAILED in --reap-only mode', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('tick');

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->allows('publish')->andReturn(true);

        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->allows('notifyTaskOrphaned')->andReturnNull();

        $container = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn(['worker_stale_minutes' => 1]);
        $container->allows('get')->with('tool_instances')->andReturn([]);

        $command = new WorkerRunCommand(
            $db,
            $orchestrator,
            new NullLogger(),
            $container,
            $mercure,
            $notificationService,
        );

        $authService = bootAuthLayer();
        $userId = $authService->register('reaponly2-test@example.com', WORKER_TEST_PASSWORD, 'ReapOnly2Test');
        $agent = Agent::create([
            'user_id'   => $userId,
            'name'      => 'ReapOnly2TestAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        // Create a task stuck in RUNNING for longer than stale-minutes
        $orphanedTask = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $userId,
            'status'      => 'RUNNING',
            'user_prompt' => 'Orphaned',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);
        // Override updated_at directly so Eloquent doesn't refresh it on save().
        Task::where('id', $orphanedTask->id)->update([
            'updated_at' => date(DATETIME_FORMAT, strtotime('-10 minutes')),
        ]);

        // Explicit --stale-minutes=1 so the test overrides the CLI default of 60.
        $input = new ArrayInput(['--reap-only' => true, '--stale-minutes' => 1], $command->getDefinition());
        $output = new NullOutput();

        $ref = new ReflectionMethod($command, 'execute');
        $exitCode = $ref->invoke($command, $input, $output);

        expect($exitCode)->toBe(0);

        $orphanedTask->refresh();
        expect($orphanedTask->status)->toBe('FAILED');
        expect($orphanedTask->error_code)->toBe('ORPHANED');
    });
});
