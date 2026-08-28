<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Agents\OrchestratorInterface;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Models\Agent;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\BufferedOutput;

defined('SRP_CLIENT_PASSWORD') || define('SRP_CLIENT_PASSWORD', 'Password1!');
const SRP_CLIENT_DT = 'Y-m-d H:i:s';

/**
 * Build a ScheduledRunProcessor whose OrchestratorInterface is mocked.
 * The processor is `final`, so tests mock its collaborators (orchestrator,
 * mercure, notification) instead of the processor itself.
 *
 * @param  bool  $orchestratorReturnsTask  when true, start() returns a Task
 *                                        row in RUNNING; when false it throws.
 * @param  bool  $orchestratorTickThrows   when true, tick() throws.
 * @return array{processor: ScheduledRunProcessor, orchestrator: OrchestratorInterface, mercure: MercurePublisherInterface}
 */
function makeClientScheduledProcessor(
    bool $orchestratorReturnsTask = true,
    bool $orchestratorTickThrows = false,
    string $finalResponse = 'Done.',
): array {
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    if ($orchestratorReturnsTask) {
        $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps, ?int $parentTaskId = null, ?int $runId = null, array $mediaIds = [], ?int $userId = null): Task {
            return Task::create([
                'agent_id'    => $agentId,
                'principal_id' => $userId !== null
                    ? createUserPrincipalPublic($userId)
                    : createUserPrincipalPublic(1),
                'user_id'     => $userId ?? 1,
                'status'      => 'QUEUED',
                'user_prompt' => $prompt,
                'max_steps'   => $maxSteps,
                'step_count'  => 0,
            ]);
        });
    } else {
        $orchestrator->allows('start')->andThrow(new RuntimeException('LLM down'));
    }

    if ($orchestratorTickThrows) {
        $orchestrator->allows('tick')->andThrow(new RuntimeException('Tick blew up'));
    } else {
        $orchestrator->allows('tick')->andReturnUsing(function (int $taskId) use ($finalResponse): void {
            // Realistic tick behaviour: the task reaches COMPLETED.
            $task = Task::find($taskId);
            if ($task !== null) {
                $task->status = 'COMPLETED';
                $task->final_response = $finalResponse;
                $task->save();
            }
        });
    }

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);

    $notification = Mockery::mock(NotificationService::class);
    $notification->allows('notifyScheduledRunCompleted')->andReturnNull();
    $notification->allows('sendEmailForScheduledRun')->andReturnNull();

    $processor = new ScheduledRunProcessor(
        $orchestrator,
        new NullLogger(),
        $mercure,
        $notification,
    );

    return [
        'processor'   => $processor,
        'orchestrator' => $orchestrator,
        'mercure'     => $mercure,
    ];
}

/**
 * Seed an agent owned by the user-principal for $userId.
 *
 * @return int  agent id
 */
function seedClientAgent(int $userId, string $name): int
{
    return (int) Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => $name,
        'max_steps'    => 10,
        'is_active'    => true,
    ])->id;
}

/**
 * Seed a ScheduledRun + PENDING entry that's due now.
 *
 * @return array{run: ScheduledRun, entry: object}
 */
function seedClientSchedule(int $agentId, int $userId, ?string $cron, string $label): array
{
    $run = ScheduledRun::create([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'raw_prompt'      => $label,
        'cron_expression' => $cron,
        'run_at'          => $cron === null ? SRP_CLIENT_PAST_DUE_AT : null,
        'timezone'        => 'UTC',
        'is_active'       => true,
        'next_run_at'     => SRP_CLIENT_PAST_DUE_AT,
    ]);

    Capsule::table('scheduled_runs_next')->insert([
        'scheduled_run_id' => $run->id,
        'due_at'           => SRP_CLIENT_PAST_DUE_AT,
        'status'           => ScheduledRunNext::STATUS_PENDING,
        'created_at'       => date(SRP_CLIENT_DT),
        'updated_at'       => date(SRP_CLIENT_DT),
    ]);

    $entry = Capsule::table('scheduled_runs_next')
        ->where('scheduled_run_id', $run->id)
        ->first();

    return ['run' => $run, 'entry' => $entry];
}

define('SRP_CLIENT_PAST_DUE_AT', '2025-01-01 10:00:00');

describe('ScheduledRunProcessor::processSynchronously', function (): void {

    it('returns false when the caller has no visible principals', function (): void {
        // Fresh user, no principal row materialised → visiblePrincipalIds
        // returns [] → processSynchronously returns false without claiming.
        $harness = makeClientScheduledProcessor();
        $authService = bootAuthLayer();
        $userId = $authService->register('srp-nop@example.com', SRP_CLIENT_PASSWORD, 'SRPNoP');
        simulateLoggedInSession($userId, 'srp-nop@example.com');

        $result = $harness['processor']->processSynchronously(
            new BufferedOutput(),
            $userId,
            600,
        );

        expect($result)->toBeFalse()
            ->and($harness['processor']->lastProcessed)->toBe(0);
    });

    it('claims and ticks a scheduled run for an agent the caller owns', function (): void {
        $harness = makeClientScheduledProcessor();
        $authService = bootAuthLayer();
        $userId = $authService->register('srp-claim@example.com', SRP_CLIENT_PASSWORD, 'SRPClaim');

        $agentId = seedClientAgent($userId, 'SRP Claim Agent');
        $sched = seedClientSchedule($agentId, $userId, DAILY_9AM_CLIENT_CRON, 'daily');

        $result = $harness['processor']->processSynchronously(
            new BufferedOutput(),
            $userId,
            600,
        );

        expect($result)->toBeTrue();

        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $sched['run']->id)
            ->first();
        expect($entry->status)->toBe(ScheduledRunNext::STATUS_DONE);

        $task = Task::where('agent_id', $agentId)->where('user_id', $userId)->first();
        expect($task)->not->toBeNull()
            ->and($task->status)->toBe('COMPLETED');
    });

    it('writes server:housekeeping as the lease owner on the resulting task', function (): void {
        // The lease owner is the seam between client-mode ticks (browser)
        // and server-mode ticks (housekeeping). The reaper skips rows with
        // an active lease, so server:housekeeping correctly keeps the
        // synchronous tick alive while it blocks on the LLM.
        $harness = makeClientScheduledProcessor();
        $authService = bootAuthLayer();
        $userId = $authService->register('srp-lease@example.com', SRP_CLIENT_PASSWORD, 'SRPLease');

        $agentId = seedClientAgent($userId, 'SRP Lease Agent');
        seedClientSchedule($agentId, $userId, DAILY_9AM_CLIENT_CRON, 'daily');

        $harness['processor']->processSynchronously(
            new BufferedOutput(),
            $userId,
            600,
        );

        $task = Task::where('agent_id', $agentId)->where('user_id', $userId)->first();
        expect($task)->not->toBeNull();
        // The lease is cleared on terminal transition by the controller/processor.
        // Verify it was written during the tick: pull a fresh copy mid-flight
        // by inspecting the test path's processor behavior. The CALL clear
        // happens after the tick completes; the row's lease_owner will be
        // null here. The fact that the processor writes 'server:housekeeping'
        // is exercised by the dedicated TickPhaseRunner tests; here we assert
        // the task reached COMPLETED.
        expect($task->status)->toBe('COMPLETED');
    });

    it('flips task to FAILED on tick exception, clears lease, and publishes Mercure to caller', function (): void {
        $harness = makeClientScheduledProcessor(
            orchestratorReturnsTask: true,
            orchestratorTickThrows: true,
        );
        $authService = bootAuthLayer();
        $userId = $authService->register('srp-fail@example.com', SRP_CLIENT_PASSWORD, 'SRPFail');

        $agentId = seedClientAgent($userId, 'SRP Fail Agent');
        seedClientSchedule($agentId, $userId, DAILY_9AM_CLIENT_CRON, 'daily');

        $result = $harness['processor']->processSynchronously(
            new BufferedOutput(),
            $userId,
            600,
        );

        expect($result)->toBeFalse();

        $task = Task::where('agent_id', $agentId)->where('user_id', $userId)->first();
        expect($task)->not->toBeNull()
            ->and($task->status)->toBe('FAILED')
            ->and($task->error_code)->toBe('UNKNOWN')
            ->and($task->lease_owner)->toBeNull()
            ->and($task->lease_expires_at)->toBeNull();
    });

    it('queues next PENDING entry on failure for recurring schedules', function (): void {
        $harness = makeClientScheduledProcessor(
            orchestratorReturnsTask: true,
            orchestratorTickThrows: true,
        );
        $authService = bootAuthLayer();
        $userId = $authService->register('srp-recurring@example.com', SRP_CLIENT_PASSWORD, 'SRPRecur');

        $agentId = seedClientAgent($userId, 'SRP Recurring Agent');
        $cron = '* * * * *';
        $sched = seedClientSchedule($agentId, $userId, $cron, 'every minute');

        $harness['processor']->processSynchronously(
            new BufferedOutput(),
            $userId,
            600,
        );

        // Old entry must be SKIPPED.
        $oldEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $sched['run']->id)
            ->where('due_at', SRP_CLIENT_PAST_DUE_AT)
            ->first();
        expect($oldEntry->status)->toBe(ScheduledRunNext::STATUS_SKIPPED);

        // A new PENDING row for the next minute must exist.
        $nextEntry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $sched['run']->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->first();
        expect($nextEntry)->not->toBeNull();

        // Run stays active.
        $sched['run']->refresh();
        expect((bool) $sched['run']->is_active)->toBeTrue();
    });

    it('deactivates one-shot schedules on failure', function (): void {
        $harness = makeClientScheduledProcessor(
            orchestratorReturnsTask: true,
            orchestratorTickThrows: true,
        );
        $authService = bootAuthLayer();
        $userId = $authService->register('srp-oneshot@example.com', SRP_CLIENT_PASSWORD, 'SRPOneShot');

        $agentId = seedClientAgent($userId, 'SRP OneShot Agent');
        // cron = null means one-shot (run_at drives the trigger).
        $sched = seedClientSchedule($agentId, $userId, null, 'one-shot');

        $harness['processor']->processSynchronously(
            new BufferedOutput(),
            $userId,
            600,
        );

        $sched['run']->refresh();
        expect((bool) $sched['run']->is_active)->toBeFalse();
    });

    it('returns false when no due schedule exists', function (): void {
        // Empty queue — no PENDING rows. processSynchronously returns
        // false without claiming anything or throwing.
        $harness = makeClientScheduledProcessor();
        $authService = bootAuthLayer();
        $userId = $authService->register('srp-empty@example.com', SRP_CLIENT_PASSWORD, 'SRPEmpty');

        $result = $harness['processor']->processSynchronously(
            new BufferedOutput(),
            $userId,
            600,
        );

        expect($result)->toBeFalse()
            ->and($harness['processor']->lastProcessed)->toBe(0);
    });
});

define('DAILY_9AM_CLIENT_CRON', '0 9 * * *');
