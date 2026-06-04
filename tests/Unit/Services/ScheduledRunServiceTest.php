<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery\MockInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
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
        'user_id'   => $userId,
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
            'user_id'         => $userId,
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
            'user_id'   => $userId,
            'name'      => 'AgentB',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        ScheduledRun::create([
            'agent_id'   => $agentB->id,
            'user_id'    => $userId,
            'raw_prompt' => 'B only',
            'timezone'   => 'UTC',
            'is_active'  => true,
        ]);

        $result = $service->getRunsForAgent($agentA, $userId);
        expect($result)->toBe([]);
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
            'user_id'   => $userId,
            'name'      => 'AgentB',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        $runB = ScheduledRun::create([
            'agent_id'   => $agentB->id,
            'user_id'    => $userId,
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
            'user_id'    => $userId,
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
            'user_id'    => $userId,
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
            'user_id'    => $userId,
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

        $captured = null;
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
            'user_id'    => $userId,
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
});
