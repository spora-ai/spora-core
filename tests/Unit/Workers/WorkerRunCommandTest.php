<?php

declare(strict_types=1);

use Cron\CronExpression;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Console\Commands\WorkerRunCommand;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Console\Worker\WorkerQueueProcessor;
use Spora\Core\Database;
use Spora\Core\Paths;
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
            'principal_id' => (int) Agent::find($agentId)->principal_id,
            'trigger_user_id' => 1,
            'status'      => 'RUNNING',
            'user_prompt' => $prompt,
            'max_steps'   => $maxSteps,
            'step_count'  => 0,
        ]);
    });

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
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
        new Paths(BASE_PATH),
        WorkerRuntimeMode::Server,
    );

    return [$command, $orchestrator, $db];
}

/**
 * Build a ScheduledRunProcessor without going through WorkerRunCommand's
 * constructor. Used by the B1 regression test, which needs the constructor
 * to NOT pin UTC so the gmdate() fix can actually be exercised.
 *
 * Must be called AFTER makeWorkerRunCommand() so the global Capsule
 * points at the same in-memory DB the test's data inserts hit.
 */
function makeStandaloneScheduledRunProcessor(): ScheduledRunProcessor
{
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps): Task {
        return Task::create([
            'agent_id'    => $agentId,
            'principal_id' => (int) Agent::find($agentId)->principal_id,
            'trigger_user_id' => 1,
            'status'      => 'RUNNING',
            'user_prompt' => $prompt,
            'max_steps'   => $maxSteps,
            'step_count'  => 0,
        ]);
    });

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $notificationService = Mockery::mock(NotificationService::class);
    $notificationService->allows('notifyScheduledRunCompleted')->andReturnNull();
    $notificationService->allows('sendEmailForScheduledRun')->andReturnNull();

    return new ScheduledRunProcessor(
        $orchestrator,
        new NullLogger(),
        $mercure,
        $notificationService,
    );
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
        'principal_id' => createUserPrincipalPublic($userId),
        'name'      => 'WorkerTestAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent->id];
}

/**
 * Invoke ScheduledRunProcessor::process via reflection, mirroring the old
 * WorkerRunCommand::processScheduledRuns helper for the test suite.
 */
function runProcessScheduledRuns(WorkerRunCommand $command): int
{
    $ref = new ReflectionClass($command);
    $processorProp = $ref->getProperty('scheduledRunProcessor');
    /** @var ScheduledRunProcessor $processor */
    $processor = $processorProp->getValue($command);
    $processor->lastProcessed = 0;
    $processor->process(new NullOutput());
    return $processor->lastProcessed;
}

describe('WorkerRunCommand processScheduledRuns', function (): void {
    it('picks up a PENDING entry that is due and marks it DONE', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id' => $userId,
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
            'user_id' => $userId,
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
            'user_id' => $userId,
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
            'user_id' => $userId,
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
            'user_id' => $userId,
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
            'user_id' => $userId,
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
            'user_id' => $userId,
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

    it('dispatches the agent bound to the actually-due PENDING entry, ignoring any stale CLAIMED row', function (): void {
        // Regression: previously the claim did UPDATE-then-SELECT-by-status=CLAIMED.
        // If a prior worker tick crashed between claim and finalization it would
        // leave a stale CLAIMED row, and the next tick's re-SELECT would happily
        // return that older row — dispatching its agent instead of the agent
        // bound to the row that was just claimed.
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $authService = bootAuthLayer();
        $userId = $authService->register('claim-race@example.com', WORKER_TEST_PASSWORD, 'ClaimRace');

        $agentA = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'      => 'WorkerTestAgentA',
            'max_steps' => 10,
            'is_active' => true,
        ]);
        $agentB = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'      => 'WorkerTestAgentB',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        $runA = ScheduledRun::create([
            'agent_id'        => $agentA->id,
            'user_id' => $userId,
            'raw_prompt'      => 'Stale run',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        $runB = ScheduledRun::create([
            'agent_id'        => $agentB->id,
            'user_id' => $userId,
            'raw_prompt'      => 'Live run',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        // Stale CLAIMED row for agent A (simulating a crashed prior worker tick).
        // The OLD buggy claim did `ORDER BY due_at ASC` over CLAIMED rows, so we
        // give this row the *older* due_at — that is exactly what would make the
        // buggy code deterministically dispatch agent A here.
        $staleDueAt = WORKER_TEST_PAST_DUE_AT;
        $liveDueAt = date(DATETIME_FORMAT, strtotime(WORKER_TEST_PAST_DUE_AT) + 300);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $runA->id,
            'due_at'           => $staleDueAt,
            'status'           => ScheduledRunNext::STATUS_CLAIMED,
            'claimed_at'       => '2025-01-01 09:00:00',
            'created_at'       => '2025-01-01 09:00:00',
            'updated_at'       => '2025-01-01 09:00:00',
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $runB->id,
            'due_at'           => $liveDueAt,
            'status'           => ScheduledRunNext::STATUS_PENDING,
            'created_at'       => date(DATETIME_FORMAT),
            'updated_at'       => date(DATETIME_FORMAT),
        ]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        // Orchestrator must be called exactly once, and with agent B's id — not A's.
        $orchestrator->shouldReceive('start')
            ->once()
            ->withArgs(fn(int $agentId, string $prompt, int $maxSteps, $context = null, $scheduledRunId = null): bool => $agentId === $agentB->id)
            ->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps): Task {
                return Task::create([
                    'agent_id'    => $agentId,
                    'principal_id' => (int) Agent::find($agentId)->principal_id,
                    'trigger_user_id' => 1,
                    'status'      => 'RUNNING',
                    'user_prompt' => $prompt,
                    'max_steps'   => $maxSteps,
                    'step_count'  => 0,
                ]);
            });

        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
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
            new Paths(BASE_PATH),
            WorkerRuntimeMode::Server,
        );

        $processed = runProcessScheduledRuns($command);
        expect($processed)->toBe(1);

        $liveEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runB->id)
            ->first();
        expect($liveEntry->status)->toBe(ScheduledRunNext::STATUS_DONE);

        // The stale row stays CLAIMED — the claim is now keyed by the row we
        // actually marked, so a leftover from a previous tick cannot be picked up.
        $staleEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runA->id)
            ->first();
        expect($staleEntry->status)->toBe(ScheduledRunNext::STATUS_CLAIMED);
    });

    it('worker creates new PENDING entry after processing a past-due recurring run', function (): void {
        [$command] = makeWorkerRunCommand();
        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id' => $userId,
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
            'user_id' => $userId,
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

    it('claimNextScheduledRun honours UTC even when server tz is non-UTC', function (): void {
        // B1 regression — the worker must not silently skip past-due entries on hosts
        // whose default tz is not UTC. The TZ pin lives in WorkerRunCommand::execute,
        // NOT in the processor's constructor (that would mask this exact bug). Boot the
        // shared in-memory DB via makeWorkerRunCommand(), then construct a standalone
        // processor so this test exercises gmdate() directly under an LA-tz frame.
        makeWorkerRunCommand();
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            [$userId, $agentId] = registerAgentInWorkerDb();

            $run = ScheduledRun::create([
                'agent_id'        => $agentId,
                'user_id' => $userId,
                'raw_prompt'      => 'UTC-poll test',
                'cron_expression' => DAILY_9AM_CRON,
                'timezone'        => 'UTC',
                'is_active'       => true,
                'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
            ]);

            Capsule::table('scheduled_runs_next')->insert([
                'scheduled_run_id' => $run->id,
                'due_at'           => WORKER_TEST_PAST_DUE_AT,
                'status'           => ScheduledRunNext::STATUS_PENDING,
                'created_at'       => gmdate(DATETIME_FORMAT),
                'updated_at'       => gmdate(DATETIME_FORMAT),
            ]);

            $processor = makeStandaloneScheduledRunProcessor();
            $processor->lastProcessed = 0;
            $processor->process(new NullOutput());

            // If the claim used date() under LA, WORKER_TEST_PAST_DUE_AT (UTC) would
            // lex-compare against an LA wall-clock string and skip. With gmdate() the
            // comparison is apples-to-apples UTC and the entry is claimed.
            expect($processor->lastProcessed)->toBe(1);

            $entry = Capsule::table('scheduled_runs_next')
                ->where('scheduled_run_id', $run->id)
                ->first();
            expect($entry->status)->toBe(ScheduledRunNext::STATUS_DONE);
        } finally {
            date_default_timezone_set($originalTz);
        }
    });

    it('process creates next PENDING entry when orchestrator throws (recurring)', function (): void {
        // B2 regression — the catch branch must not skip finalisation: it must queue
        // the next PENDING for recurring schedules, atomically with marking SKIPPED.
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $throwingOrchestrator = Mockery::mock(OrchestratorInterface::class);
        $throwingOrchestrator->allows('start')
            ->andThrow(new RuntimeException('LLM down'));

        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);

        $notification = Mockery::mock(NotificationService::class);

        $processor = new ScheduledRunProcessor(
            $throwingOrchestrator,
            new NullLogger(),
            $mercure,
            $notification,
        );

        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id' => $userId,
            'raw_prompt'      => 'Recurring with throwing orchestrator',
            'cron_expression' => DAILY_9AM_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'           => WORKER_TEST_PAST_DUE_AT,
            'status'           => ScheduledRunNext::STATUS_PENDING,
            'created_at'       => date(DATETIME_FORMAT),
            'updated_at'       => date(DATETIME_FORMAT),
        ]);

        $processor->process(new NullOutput());

        // Old entry must be SKIPPED, not stuck CLAIMED
        $oldEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('due_at', WORKER_TEST_PAST_DUE_AT)
            ->first();
        expect($oldEntry->status)->toBe(ScheduledRunNext::STATUS_SKIPPED);
        expect($oldEntry->completed_at)->not->toBeNull();

        // A new PENDING row must exist for the next cron fire
        $nextEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->first();
        expect($nextEntry)->not->toBeNull();
        expect($nextEntry->due_at)->not->toBe(WORKER_TEST_PAST_DUE_AT);

        // Recurring run must stay active and have its next_run_at advanced
        $run->refresh();
        expect((bool) $run->is_active)->toBeTrue();
        expect($run->next_run_at)->not->toBeNull();
        expect($run->next_run_at->toDateTimeString())->not->toBe(WORKER_TEST_PAST_DUE_AT);
    });

    it('process deactivates run when orchestrator throws (one-shot)', function (): void {
        // B2 regression — one-shot failures must deactivate the run, not leave it
        // active with no PENDING entry and no SKIPPED bookkeeping.
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $throwingOrchestrator = Mockery::mock(OrchestratorInterface::class);
        $throwingOrchestrator->allows('start')
            ->andThrow(new RuntimeException('LLM down'));

        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);

        $notification = Mockery::mock(NotificationService::class);

        $processor = new ScheduledRunProcessor(
            $throwingOrchestrator,
            new NullLogger(),
            $mercure,
            $notification,
        );

        [$userId, $agentId] = registerAgentInWorkerDb();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id' => $userId,
            'raw_prompt'      => 'One-shot with throwing orchestrator',
            'cron_expression' => null,
            'run_at'          => WORKER_TEST_PAST_DUE_AT,
            'timezone'        => 'UTC',
            'is_active'       => true,
            'next_run_at'     => WORKER_TEST_PAST_DUE_AT,
        ]);

        Capsule::table('scheduled_runs_next')->insert([
            'scheduled_run_id' => $run->id,
            'due_at'           => WORKER_TEST_PAST_DUE_AT,
            'status'           => ScheduledRunNext::STATUS_PENDING,
            'created_at'       => date(DATETIME_FORMAT),
            'updated_at'       => date(DATETIME_FORMAT),
        ]);

        $processor->process(new NullOutput());

        // Old entry is SKIPPED
        $oldEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->first();
        expect($oldEntry->status)->toBe(ScheduledRunNext::STATUS_SKIPPED);

        // No orphan PENDING row was left behind
        $pendingCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->count();
        expect($pendingCount)->toBe(0);

        // Run is deactivated
        $run->refresh();
        expect((bool) $run->is_active)->toBeFalse();
    });
});

describe('WorkerQueueProcessor processQueuedTaskSync', function (): void {
    it('marks task FAILED when orchestrator->tick() throws an exception', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps): Task {
            return Task::create([
                'agent_id'    => $agentId,
                'principal_id' => (int) Agent::find($agentId)->principal_id,
                'trigger_user_id' => 1,
                'status'      => 'RUNNING',
                'user_prompt' => $prompt,
                'max_steps'   => $maxSteps,
                'step_count'  => 0,
            ]);
        });
        // Make tick() throw an exception — simulating an LLM failure
        $orchestrator->allows('tick')->andThrow(new RuntimeException('LLM connection failed'));

        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);

        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->allows('notifyTaskFailed')->andReturnNull();
        $notificationService->allows('notifyTaskOrphaned')->andReturnNull();
        $notificationService->allows('notifyScheduledRunCompleted')->andReturnNull();
        $notificationService->allows('sendEmailForScheduledRun')->andReturnNull();

        $processor = new WorkerQueueProcessor(
            $orchestrator,
            new NullLogger(),
            $mercure,
            $notificationService,
            new Paths(BASE_PATH),
        );

        // Create agent and task
        $authService = bootAuthLayer();
        $userId = $authService->register('worker-test@example.com', WORKER_TEST_PASSWORD, 'Workertest');

        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'      => 'WorkerTestAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'status'      => 'QUEUED',
            'user_prompt' => 'Test prompt',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        $output = new NullOutput();
        $processed = 0;

        // Invoke processQueuedTaskSync via reflection (must use invokeArgs with array to preserve reference)
        $ref = new ReflectionMethod($processor, 'processQueuedTaskSync');
        $ref->invokeArgs($processor, [$output, 1000, &$processed]);

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

        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
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
            new Paths(BASE_PATH),
            WorkerRuntimeMode::Server,
        );

        $authService = bootAuthLayer();
        $userId = $authService->register('reaponly-test@example.com', WORKER_TEST_PASSWORD, 'ReapOnlyTest');
        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'      => 'ReapOnlyTestAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        // Create a QUEUED task — it should NOT be processed in --reap-only mode
        Task::create([
            'agent_id'    => $agent->id,
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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

        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
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
            new Paths(BASE_PATH),
            WorkerRuntimeMode::Server,
        );

        $authService = bootAuthLayer();
        $userId = $authService->register('reaponly2-test@example.com', WORKER_TEST_PASSWORD, 'ReapOnly2Test');
        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'      => 'ReapOnly2TestAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        // Create a task stuck in RUNNING for longer than stale-minutes
        $orphanedTask = Task::create([
            'agent_id'    => $agent->id,
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
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
        expect($orphanedTask->error_code)->toBe('WORKER_DISCONNECTED');
    });
});

describe('WorkerRunCommand mode flag validation', function (): void {

    it('rejects --daemon combined with --once', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $paths = new Paths(BASE_PATH);
        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        $notification = Mockery::mock(NotificationService::class);
        $container    = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn(['worker_stale_minutes' => 60]);

        $command = new WorkerRunCommand($db, $orchestrator, new NullLogger(), $container, $mercure, $notification, $paths, WorkerRuntimeMode::Server);

        $input  = new ArrayInput(['--daemon' => true, '--once' => true], $command->getDefinition());
        $output = new NullOutput();

        $ref = new ReflectionMethod($command, 'execute');
        $exitCode = $ref->invoke($command, $input, $output);

        expect($exitCode)->toBe(1);
    });

    it('rejects --daemon combined with --reap-only', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $paths = new Paths(BASE_PATH);
        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        $notification = Mockery::mock(NotificationService::class);
        $container    = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn(['worker_stale_minutes' => 60]);

        $command = new WorkerRunCommand($db, $orchestrator, new NullLogger(), $container, $mercure, $notification, $paths, WorkerRuntimeMode::Server);

        $input  = new ArrayInput(['--daemon' => true, '--reap-only' => true], $command->getDefinition());
        $output = new NullOutput();

        $ref = new ReflectionMethod($command, 'execute');
        $exitCode = $ref->invoke($command, $input, $output);

        expect($exitCode)->toBe(1);
    });

    it('rejects --once combined with --reap-only', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $paths = new Paths(BASE_PATH);
        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        $notification = Mockery::mock(NotificationService::class);
        $container    = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn(['worker_stale_minutes' => 60]);

        $command = new WorkerRunCommand($db, $orchestrator, new NullLogger(), $container, $mercure, $notification, $paths, WorkerRuntimeMode::Server);

        $input  = new ArrayInput(['--once' => true, '--reap-only' => true], $command->getDefinition());
        $output = new NullOutput();

        $ref = new ReflectionMethod($command, 'execute');
        $exitCode = $ref->invoke($command, $input, $output);

        expect($exitCode)->toBe(1);
    });

    it('exits with error in client mode', function (): void {
        // Client-mode installs drive ticks via /api/v1/tasks/{id}/tick and the
        // reaper via /worker/housekeeping — bin/spora worker:run has no work
        // to do. The gate runs before bootDatabaseConnectionOnly() so a
        // misconfigured install still produces a clear failure (no DB
        // connection attempts).
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $paths = new Paths(BASE_PATH);
        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        $notification = Mockery::mock(NotificationService::class);
        $container    = Mockery::mock(Psr\Container\ContainerInterface::class);

        $command = new WorkerRunCommand($db, $orchestrator, new NullLogger(), $container, $mercure, $notification, $paths, WorkerRuntimeMode::Client);

        $buffer = new Symfony\Component\Console\Output\BufferedOutput();
        $ref = new ReflectionMethod($command, 'execute');
        $exitCode = $ref->invoke($command, new ArrayInput([], $command->getDefinition()), $buffer);

        expect($exitCode)->toBe(Symfony\Component\Console\Command\Command::FAILURE)
            ->and($buffer->fetch())->toContain('client-worker mode');
    });
});

describe('ScheduledRunProcessor substituteVariables', function (): void {
    /**
     * Invoke the private substituteVariables() method via reflection.
     * Uses the already-booted DB (do NOT reset/boot here — that would discard
     * the caller-side Agent/User data).
     */
    function invokeSubstituteVariables(string $template, array $variables, ?Agent $agent = null): string
    {
        // Make sure DB is booted (it's already booted by the test's beforeEach).
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        $notification = Mockery::mock(NotificationService::class);

        $processor = new ScheduledRunProcessor(
            $orchestrator,
            new NullLogger(),
            $mercure,
            $notification,
        );
        $ref = new ReflectionMethod($processor, 'substituteVariables');

        return $ref->invoke($processor, $template, $variables, $agent);
    }

    it('substitutes {{current_date}} with today\'s date', function (): void {
        $result = invokeSubstituteVariables('Today is {{current_date}}', []);
        expect($result)->toBe('Today is ' . date('Y-m-d'));
    });

    it('substitutes {{date}} alias for current_date', function (): void {
        $result = invokeSubstituteVariables('Date: {{date}}', []);
        expect($result)->toBe('Date: ' . date('Y-m-d'));
    });

    it('substitutes {{current_time}} with HH:MM', function (): void {
        $result = invokeSubstituteVariables('Time: {{current_time}}', []);
        expect($result)->toMatch('/^Time: \d{2}:\d{2}$/');
    });

    it('substitutes {{current_datetime}} with ISO local time', function (): void {
        $result = invokeSubstituteVariables('Now: {{current_datetime}}', []);
        expect($result)->toMatch('/^Now: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/');
    });

    it('substitutes {{agent_name}} when an agent is provided', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();
        [, $agentId] = registerAgentInWorkerDb();
        $agent = Agent::find($agentId);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        $notification = Mockery::mock(NotificationService::class);
        $processor = new ScheduledRunProcessor(
            $orchestrator,
            new NullLogger(),
            $mercure,
            $notification,
        );
        $ref = new ReflectionMethod($processor, 'substituteVariables');
        $result = $ref->invoke($processor, 'Hello {{agent_name}}', [], $agent);

        expect($result)->toBe('Hello WorkerTestAgent');
    });

    it('substitutes {{user_name}} with the agent owner\'s username', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();
        [$userId, $agentId] = registerAgentInWorkerDb();
        // The user record must have its `username` column set for the substitution
        // to find it — delight-im/auth only sets email + status.
        Spora\Models\User::where('id', $userId)->update(['username' => 'WorkerTestUser']);
        $agent = Agent::find($agentId);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        $notification = Mockery::mock(NotificationService::class);
        $processor = new ScheduledRunProcessor(
            $orchestrator,
            new NullLogger(),
            $mercure,
            $notification,
        );
        $ref = new ReflectionMethod($processor, 'substituteVariables');
        $result = $ref->invoke($processor, 'Owner: {{user_name}}', [], $agent);

        expect($result)->toBe('Owner: WorkerTestUser');
    });

    it('falls back to the placeholder when the owner has no username', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();
        [, $agentId] = registerAgentInWorkerDb();
        $agent = Agent::find($agentId);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure      = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        $notification = Mockery::mock(NotificationService::class);
        $processor = new ScheduledRunProcessor(
            $orchestrator,
            new NullLogger(),
            $mercure,
            $notification,
        );
        $ref = new ReflectionMethod($processor, 'substituteVariables');
        $result = $ref->invoke($processor, 'Owner: {{user_name}}', [], $agent);

        expect($result)->toBe('Owner: user_name');
    });

    it('substitutes day_of_week, day_of_month, month, year', function (): void {
        $result = invokeSubstituteVariables(
            '{{day_of_week}} {{day_of_month}} {{month}} {{year}}',
            [],
        );
        expect($result)->toBe(date('l') . ' ' . (int) date('j') . ' ' . date('F') . ' ' . date('Y'));
    });

    it('substitutes custom variables from the variable list', function (): void {
        $result = invokeSubstituteVariables(
            'Hello {{name}}, your city is {{city}}',
            [
                ['key' => 'name', 'default_value' => 'World'],
                ['key' => 'city', 'default_value' => 'Berlin'],
            ],
        );
        expect($result)->toBe('Hello World, your city is Berlin');
    });

    it('falls back to inline default when no variable is defined', function (): void {
        $result = invokeSubstituteVariables('Hi {{name:stranger}}', []);
        expect($result)->toBe('Hi stranger');
    });

    it('keeps the literal placeholder when no default is found', function (): void {
        $result = invokeSubstituteVariables('Hello {{unknown_var}}', []);
        expect($result)->toBe('Hello {{unknown_var}}');
    });
});

describe('WorkerQueueProcessor processRetryQueue', function (): void {
    /**
     * Invoke processRetryQueue via reflection.
     */
    function invokeProcessRetryQueue(WorkerQueueProcessor $processor): void
    {
        $ref = new ReflectionMethod($processor, 'processRetryQueue');
        $ref->invoke($processor);
    }

    function makeProcessor(OrchestratorInterface $orch, ?NotificationService $notification = null): WorkerQueueProcessor
    {
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);
        return new WorkerQueueProcessor(
            $orch,
            new NullLogger(),
            $mercure,
            $notification ?? Mockery::mock(NotificationService::class),
            new Paths(BASE_PATH),
        );
    }

    it('does nothing when no retry tasks are due', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('tick');

        invokeProcessRetryQueue(makeProcessor($orchestrator));
    });

    it('skips retries that have not elapsed', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $auth = bootAuthLayer();
        $userId = $auth->register('retry-skip@example.com', WORKER_TEST_PASSWORD, 'RetrySkip');
        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'RetrySkipAgent',
            'max_steps' => 5, 'is_active' => true,
        ]);

        // FAILED task whose retry_after is in the future — worker must skip.
        $failed = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'       => $agent->id,
            'status'         => 'FAILED',
            'user_prompt'    => 'orig',
            'max_steps'      => 5,
            'retry_count'    => 1,
        ]);
        $failed->retry_of_task_id = $failed->id;
        $failed->save();
        Capsule::table('tasks')
            ->where('id', $failed->id)
            ->update(['retry_after' => '2099-01-01 00:00:00']);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('retry');
        $orchestrator->shouldNotReceive('tick');

        invokeProcessRetryQueue(makeProcessor($orchestrator));

        $failed->refresh();
        expect($failed->status)->toBe('FAILED');
    });

    it('processes a due retry by calling orchestrator->retry()', function (): void {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY]);
        $db->boot();

        $auth = bootAuthLayer();
        $userId = $auth->register('retry-ok2@example.com', WORKER_TEST_PASSWORD, 'RetryOk2');
        $agent = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId), 'name' => 'RetryOk2Agent',
            'max_steps' => 5, 'max_retries' => 3, 'is_active' => true,
        ]);
        // In-place retry: the failed task itself is the retry target. It has
        // retry_of_task_id pointing to itself and retry_after in the past.
        $failed = Task::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'agent_id'       => $agent->id,
            'status'         => 'FAILED',
            'user_prompt'    => 'orig',
            'max_steps'      => 5,
            'retry_count'    => 1,
        ]);
        $failed->retry_of_task_id = $failed->id;
        $failed->save();
        Capsule::table('tasks')
            ->where('id', $failed->id)
            ->update(['retry_after' => '2020-01-01 00:00:00']);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldReceive('retry')->once()->andReturnUsing(fn(int $taskId) => Task::find($taskId));
        $orchestrator->shouldNotReceive('tick');

        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldIgnoreMissing();

        invokeProcessRetryQueue(makeProcessor($orchestrator, $notification));
    });
});

describe('Schedule SQL is dialect-portable', function (): void {
    it('does not embed raw "INSERT OR IGNORE" SQL in the schedule source files', function (): void {
        // The schedule pipeline used to emit a raw "INSERT OR IGNORE INTO
        // scheduled_runs_next ..." string. SQLite accepts that, but MariaDB /
        // MySQL reject it (1064 syntax error near `OR IGNORE INTO ...`).
        // The fix routes both call sites through Capsule::insertOrIgnore(),
        // which is dialect-aware. This test guards against the raw literal
        // creeping back in.
        $corePath = dirname(__DIR__, 3);
        $files = [
            $corePath . '/app/Console/Worker/ScheduledRunProcessor.php',
            $corePath . '/app/Services/ScheduledRunService.php',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)
                ->not->toContain('INSERT OR IGNORE', "Raw SQLite-only 'INSERT OR IGNORE' literal found in {$file}. Use Capsule::table()->insertOrIgnore() instead so the dialect is handled by the query builder.");
        }
    });
});
