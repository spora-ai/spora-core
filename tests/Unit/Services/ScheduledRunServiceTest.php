<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery\MockInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\GroupService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ScheduledRunService;

defined('SCHEDULED_RUN_TEST_PASSWORD') || define('SCHEDULED_RUN_TEST_PASSWORD', 'Password1!');
const SCHEDULED_RUN_TEST_CRON = '0 9 * * *';
const SCHEDULED_RUN_TEST_BAD_CRON = 'not-a-cron';

/**
 * @param  MockInterface&OrchestratorInterface  $orchestrator
 * @param  MockInterface&MercurePublisherInterface  $mercure
 */
function makeScheduledRunService(?OrchestratorInterface $orchestrator = null, ?MercurePublisherInterface $mercure = null): ScheduledRunService
{
    $orchestrator ??= Mockery::mock(OrchestratorInterface::class);
    $mercure      ??= Mockery::mock(MercurePublisherInterface::class);

    // Default stubs that callers can override.
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
    $mercure->allows('publish')->andReturn(true);

    return new ScheduledRunService($orchestrator, $mercure);
}

function createScheduledRunUserAgent(): array
{
    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "scheduled-run-{$seq}@example.com", SCHEDULED_RUN_TEST_PASSWORD);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'      => 'SRTestAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent->id];
}

describe('ScheduledRunService::getRunsForAgent', function (): void {

    it('returns null when the agent does not exist', function (): void {
        $service = makeScheduledRunService();
        expect($service->getRunsForAgent(9999, 1))->toBeNull();
    });

    it('returns an empty list when the agent has no scheduled runs', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        $result = $service->getRunsForAgent($agentId, $userId);
        expect($result)->toBe([]);
    });

    it('returns the runs for the requested agent', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id' => $userId,
            'raw_prompt'      => 'Daily',
            'cron_expression' => SCHEDULED_RUN_TEST_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);

        $result = $service->getRunsForAgent($agentId, $userId);
        expect($result)->toHaveCount(1);
        expect($result[0]['cron_expression'])->toBe(SCHEDULED_RUN_TEST_CRON);
    });

    it('does not return runs from a different agent', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentA] = createScheduledRunUserAgent();

        $agentB = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'      => 'AgentB',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        ScheduledRun::create([
            'agent_id'   => $agentB->id,
            'user_id' => $userId,
            'raw_prompt' => 'B only',
            'timezone'   => 'UTC',
            'is_active'  => true,
        ]);

        $result = $service->getRunsForAgent($agentA, $userId);
        expect($result)->toBe([]);
    });

    it('lets a plain group member read scheduled runs (regression for stale-cache-group bug)', function (): void {
        // Owner / group / agent setup
        $service = makeScheduledRunService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'sr-owner@example.com', SCHEDULED_RUN_TEST_PASSWORD);
        $plainMemberId = bootAuth($auth, 'sr-plain@example.com', SCHEDULED_RUN_TEST_PASSWORD);

        $principalService = new PrincipalService(new PrincipalResolver());
        // Both users need their user-principal materialised for the resolver
        // to count them as part of any visible-principal set.
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($plainMemberId);

        $groupService = new GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'SR-ReadGroup');
        $groupService->addMember((int) $group->id, (int) $plainMemberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);

        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;

        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'      => 'SR-GroupAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        ScheduledRun::create([
            'agent_id'        => $agent->id,
            'user_id'         => $ownerId,
            'raw_prompt'      => 'shared daily',
            'cron_expression' => SCHEDULED_RUN_TEST_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);

        // Owner creates the run with cron — already done above. Now a plain
        // group member (no owner/admin role) requests the list.
        $result = $service->getRunsForAgent($agent->id, $plainMemberId);
        expect($result)->not->toBeNull();
        expect($result)->toHaveCount(1);
        expect($result[0]['cron_expression'])->toBe(SCHEDULED_RUN_TEST_CRON);
    });

    it('still denies a plain member from creating a scheduled run (write-side gate)', function (): void {
        $service = makeScheduledRunService();
        $auth = bootAuthLayer();
        $ownerId = bootAuth($auth, 'sr-w-owner@example.com', SCHEDULED_RUN_TEST_PASSWORD);
        $plainMemberId = bootAuth($auth, 'sr-w-plain@example.com', SCHEDULED_RUN_TEST_PASSWORD);

        $principalService = new PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $principalService->ensureUserPrincipal($plainMemberId);

        $groupService = new GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'SR-WriteDeny');
        $groupService->addMember((int) $group->id, (int) $plainMemberId, Spora\Models\GroupMembership::ROLE_MEMBER, (int) $ownerId);

        $groupPrincipalId = (int) $principalService->ensureGroupPrincipal((int) $group->id)->id;
        $agent = Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'      => 'SR-WriteDenyAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        expect(fn() => $service->createRun($agent->id, $plainMemberId, [
            'raw_prompt'      => 'plain member attempt',
            'cron_expression' => SCHEDULED_RUN_TEST_CRON,
            'timezone'        => 'UTC',
        ]))->toThrow(Spora\Services\Exceptions\AgentNotFoundException::class);
    });
});

describe('ScheduledRunService::createRun', function (): void {

    it('throws when the agent does not exist', function (): void {
        $service = makeScheduledRunService();
        expect(fn() => $service->createRun(9999, 1, ['raw_prompt' => 'x']))
            ->toThrow(RuntimeException::class, 'Agent not found');
    });

    it('creates a recurring run with cron expression and inserts a PENDING next entry', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        $result = $service->createRun($agentId, $userId, [
            'raw_prompt'      => 'Daily reminder',
            'cron_expression' => SCHEDULED_RUN_TEST_CRON,
            'timezone'        => 'UTC',
        ]);

        expect($result['scheduled_run']['agent_id'])->toBe($agentId);
        expect($result['scheduled_run']['cron_expression'])->toBe(SCHEDULED_RUN_TEST_CRON);
        expect($result['scheduled_run']['is_active'])->toBeTrue();
        expect($result['scheduled_run']['next_run_at'])->not->toBeNull();

        $pendingCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $result['scheduled_run']['id'])
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->count();
        expect($pendingCount)->toBe(1);
    });

    it('creates a one-shot run with run_at', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        $runAt = (new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $result = $service->createRun($agentId, $userId, [
            'raw_prompt' => 'One shot',
            'run_at'     => $runAt,
            'timezone'   => 'UTC',
        ]);

        expect($result['scheduled_run']['cron_expression'])->toBeNull();
        expect($result['scheduled_run']['run_at'])->not->toBeNull();
        expect($result['scheduled_run']['next_run_at'])->not->toBeNull();
    });

    it('throws on invalid cron expression', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        expect(fn() => $service->createRun($agentId, $userId, [
            'raw_prompt'      => 'Bad cron',
            'cron_expression' => SCHEDULED_RUN_TEST_BAD_CRON,
            'timezone'        => 'UTC',
        ]))->toThrow(Exception::class);
    });

    it('anchors one-shot run_at to schedule tz when offset is absent', function (): void {
        // B3 — the one-shot path now anchors offset-less run_at to the schedule tz
        // before converting to UTC, instead of using the server-local frame.
        // 2026-04-20T10:00:00 in Europe/Berlin (CEST, +02:00) = 08:00:00 UTC.
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        $result = $service->createRun($agentId, $userId, [
            'raw_prompt' => 'Berlin one-shot',
            'run_at'     => '2026-04-20T10:00:00',
            'timezone'   => 'Europe/Berlin',
        ]);

        $runId = $result['scheduled_run']['id'];
        $row = Capsule::table('scheduled_runs')->where('id', $runId)->first();
        expect($row->run_at)->toBe('2026-04-20 08:00:00');
        expect($row->next_run_at)->toBe('2026-04-20 08:00:00');
    });

    it('throws on invalid timezone string', function (): void {
        // B4 — controller already 422s on bad IANA ids, but the service guard makes
        // the contract explicit so non-HTTP callers fail loudly too.
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        expect(fn() => $service->createRun($agentId, $userId, [
            'raw_prompt' => 'Bad tz',
            'run_at'     => '2026-04-20T10:00:00',
            'timezone'   => 'Not/A_Zone',
        ]))->toThrow(DateInvalidTimeZoneException::class);
    });

    it('writes created_at and updated_at in UTC', function (): void {
        // B7 — even if the server default tz is non-UTC, the timestamps written to
        // scheduled_runs must be UTC so they line up with the UTC-stored due_at /
        // next_run_at columns on the same row.
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $service = makeScheduledRunService();
            [$userId, $agentId] = createScheduledRunUserAgent();

            $expectedUtcNow = gmdate('Y-m-d H:i:s');
            $result = $service->createRun($agentId, $userId, [
                'raw_prompt'      => 'UTC timestamp test',
                'cron_expression' => SCHEDULED_RUN_TEST_CRON,
                'timezone'        => 'UTC',
            ]);

            $row = Capsule::table('scheduled_runs')->where('id', $result['scheduled_run']['id'])->first();

            // Parse both sides as epoch seconds and assert they match within a 2-second tolerance
            // (Pest wall-clock skew across the gmdate() write and the test read).
            $rowCreatedAt = strtotime($row->created_at . ' UTC');
            $rowUpdatedAt = strtotime($row->updated_at . ' UTC');
            $expectedTs   = strtotime($expectedUtcNow . ' UTC');

            expect($rowCreatedAt)->toBeGreaterThanOrEqual($expectedTs - 2);
            expect($rowCreatedAt)->toBeLessThanOrEqual($expectedTs + 2);
            expect($rowUpdatedAt)->toBeGreaterThanOrEqual($expectedTs - 2);
            expect($rowUpdatedAt)->toBeLessThanOrEqual($expectedTs + 2);
        } finally {
            date_default_timezone_set($originalTz);
        }
    });

    it('triggerRun writes scheduled_runs and scheduled_runs_next timestamps in UTC', function (): void {
        // B7 follow-up — the manual-trigger path (POST /api/v1/agents/{id}/scheduled-runs/{runId}/trigger)
        // writes claimed_at, completed_at, created_at, updated_at, last_run_at. All must
        // be UTC for parity with the claim path.
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $service = makeScheduledRunService();
            [$userId, $agentId] = createScheduledRunUserAgent();

            $created = $service->createRun($agentId, $userId, [
                'raw_prompt'      => 'Trigger UTC test',
                'cron_expression' => SCHEDULED_RUN_TEST_CRON,
                'timezone'        => 'UTC',
            ]);
            $runId = $created['scheduled_run']['id'];

            // strtotime() with no TZ suffix uses the application default (LA here);
            // suffix ' UTC' forces UTC parsing so gmdate() and strtotime() agree.
            $expectedTs = strtotime(gmdate('Y-m-d H:i:s') . ' UTC');

            $service->triggerRun($runId, $agentId, $userId);

            // Raw rows: avoid Eloquent's 'datetime' cast (see reschedule test).
            $row = Capsule::table('scheduled_runs')->where('id', $runId)->first();
            expect($row->last_run_at)->not->toBeNull();
            expect(strtotime($row->last_run_at . ' UTC'))->toBeGreaterThanOrEqual($expectedTs - 2);
            expect(strtotime($row->last_run_at . ' UTC'))->toBeLessThanOrEqual($expectedTs + 2);
            expect(strtotime($row->updated_at . ' UTC'))->toBeGreaterThanOrEqual($expectedTs - 2);
            expect(strtotime($row->updated_at . ' UTC'))->toBeLessThanOrEqual($expectedTs + 2);

            $entry = Capsule::table('scheduled_runs_next')
                ->where('scheduled_run_id', $runId)
                ->where('status', ScheduledRunNext::STATUS_DONE)
                ->first();
            expect($entry)->not->toBeNull();
            expect(strtotime($entry->claimed_at . ' UTC'))->toBeGreaterThanOrEqual($expectedTs - 2);
            expect(strtotime($entry->claimed_at . ' UTC'))->toBeLessThanOrEqual($expectedTs + 2);
            expect(strtotime($entry->completed_at . ' UTC'))->toBeGreaterThanOrEqual($expectedTs - 2);
            expect(strtotime($entry->completed_at . ' UTC'))->toBeLessThanOrEqual($expectedTs + 2);
        } finally {
            date_default_timezone_set($originalTz);
        }
    });
});

describe('ScheduledRunService::getRun', function (): void {

    it('returns null when agent does not exist', function (): void {
        $service = makeScheduledRunService();
        expect($service->getRun(1, 9999, 1))->toBeNull();
    });

    it('returns null when run does not exist', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        expect($service->getRun(9999, $agentId, $userId))->toBeNull();
    });

    it('returns null when run belongs to a different agent', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentA] = createScheduledRunUserAgent();

        $agentB = Agent::create([
            'principal_id' => $this->createUserPrincipal($userId),
            'name'      => 'AgentB',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        $runB = ScheduledRun::create([
            'agent_id'   => $agentB->id,
            'user_id' => $userId,
            'raw_prompt' => 'B',
            'timezone'   => 'UTC',
            'is_active'  => true,
        ]);

        expect($service->getRun($runB->id, $agentA, $userId))->toBeNull();
    });
});

describe('ScheduledRunService::updateRun', function (): void {

    it('returns null when the run does not exist', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        expect($service->updateRun(9999, $agentId, $userId, ['raw_prompt' => 'x']))->toBeNull();
    });

    it('updates the prompt and persists the change', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        $run = ScheduledRun::create([
            'agent_id'   => $agentId,
            'user_id' => $userId,
            'raw_prompt' => 'before',
            'timezone'   => 'UTC',
            'is_active'  => true,
        ]);

        $result = $service->updateRun($run->id, $agentId, $userId, [
            'raw_prompt' => 'after',
        ]);

        expect($result['scheduled_run']['raw_prompt'])->toBe('after');
        $run->refresh();
        expect($run->raw_prompt)->toBe('after');
    });

    it('toggles is_active', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        $run = ScheduledRun::create([
            'agent_id'   => $agentId,
            'user_id' => $userId,
            'raw_prompt' => 'pause me',
            'timezone'   => 'UTC',
            'is_active'  => true,
        ]);

        $service->updateRun($run->id, $agentId, $userId, ['is_active' => false]);
        $run->refresh();
        expect((bool) $run->is_active)->toBeFalse();

        $service->updateRun($run->id, $agentId, $userId, ['is_active' => true]);
        $run->refresh();
        expect((bool) $run->is_active)->toBeTrue();
    });
});

describe('ScheduledRunService::deleteRun', function (): void {

    it('returns false when run does not exist', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();
        expect($service->deleteRun(9999, $agentId, $userId))->toBeFalse();
    });

    it('deletes the run and returns true', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        $run = ScheduledRun::create([
            'agent_id'   => $agentId,
            'user_id' => $userId,
            'raw_prompt' => 'delete me',
            'timezone'   => 'UTC',
            'is_active'  => true,
        ]);

        expect($service->deleteRun($run->id, $agentId, $userId))->toBeTrue();
        expect(ScheduledRun::find($run->id))->toBeNull();
    });
});

describe('ScheduledRunService::triggerRun', function (): void {

    it('throws when the agent does not exist', function (): void {
        $service = makeScheduledRunService();
        expect(fn() => $service->triggerRun(1, 9999, 1))
            ->toThrow(RuntimeException::class, 'Agent not found');
    });

    it('throws when the run does not exist', function (): void {
        $service = makeScheduledRunService();
        [$userId, $agentId] = createScheduledRunUserAgent();

        expect(fn() => $service->triggerRun(9999, $agentId, $userId))
            ->toThrow(RuntimeException::class, 'Scheduled run not found');
    });

    it('returns a task id and marks the run as triggered', function (): void {
        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $mercure = Mockery::mock(MercurePublisherInterface::class);

        $captured = ['agentId' => -1, 'prompt' => '', 'maxSteps' => 0];
        $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps) use (&$captured): Task {
            $captured = ['agentId' => $agentId, 'prompt' => $prompt, 'maxSteps' => $maxSteps];
            return Task::create([
                'agent_id'    => $agentId,
                'user_id'     => 1,
                'status'      => 'RUNNING',
                'user_prompt' => $prompt,
                'max_steps'   => $maxSteps,
                'step_count'  => 0,
            ]);
        });
        $mercure->allows('publish')->andReturn(true);

        $service = new ScheduledRunService($orchestrator, $mercure);
        [$userId, $agentId] = createScheduledRunUserAgent();

        $run = ScheduledRun::create([
            'agent_id'   => $agentId,
            'user_id' => $userId,
            'raw_prompt' => 'trigger me',
            'timezone'   => 'UTC',
            'is_active'  => true,
        ]);

        $result = $service->triggerRun($run->id, $agentId, $userId);

        expect($result)->toHaveKey('task_id');
        expect($result)->toHaveKey('scheduled_run');
        expect($result['task_id'])->toBeInt();
        expect($captured['agentId'])->toBe($agentId);
        expect($captured['prompt'])->toBe('trigger me');
    });

    it('on a recurring run, inserts a fresh PENDING next entry into scheduled_runs_next', function (): void {
        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $mercure = Mockery::mock(MercurePublisherInterface::class);
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
        $mercure->allows('publish')->andReturn(true);

        $service = new ScheduledRunService($orchestrator, $mercure);
        [$userId, $agentId] = createScheduledRunUserAgent();

        $run = ScheduledRun::create([
            'agent_id'        => $agentId,
            'user_id' => $userId,
            'raw_prompt'      => 'recurring trigger',
            'cron_expression' => SCHEDULED_RUN_TEST_CRON,
            'timezone'        => 'UTC',
            'is_active'       => true,
        ]);

        $service->triggerRun($run->id, $agentId, $userId);

        $pendingCount = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->count();

        expect($pendingCount)->toBe(1);
    });
});
