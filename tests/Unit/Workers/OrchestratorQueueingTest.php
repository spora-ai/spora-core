<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;

function mockLlmForQueue(LLMDriverInterface $driver): DriverFactory
{
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    return $factory;
}

describe('Orchestrator queueing (always-QUEUE behavior)', function (): void {
    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = $this->authService->register('queueing@example.com', 'Password1!', 'Queueing');

        // Mocked LLM — credentials don't matter, only the call assertions.
        $config = LLMDriverConfiguration::create([
            'principal_id' => null,
            'name'          => 'Queueing Test Global',
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);

        $this->agent = Agent::create([
            'principal_id' => createUserPrincipalPublic($this->userId),
            'name'                 => 'Queueing Test Agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);
    });

    it('start() creates a QUEUED task that does NOT trigger the LLM', function (): void {
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->shouldNotReceive('complete');

        $orch = new Orchestrator(
            mockLlmForQueue($mock),
            new OrchestratorConfig(logger: new NullLogger()),
        );

        $task = $orch->start($this->agent->id, 'Hello', maxSteps: 10);

        expect($task->status)->toBe('QUEUED')
            ->and($task->user_prompt)->toBe('Hello');
    });

    it('tick() advances a QUEUED task to COMPLETED via the LLM (after claim)', function (): void {
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->andReturn(new LLMResponse('Done.', [], 5, 3, 'cmp_1'));
        $mock->allows('getProviderName')->andReturn('mock');
        $mock->allows('getModelName')->andReturn('mock-model');

        $orch = new Orchestrator(
            mockLlmForQueue($mock),
            new OrchestratorConfig(logger: new NullLogger()),
        );

        $task = $orch->start($this->agent->id, 'Hello worker', maxSteps: 10);
        expect($task->status)->toBe('QUEUED');

        claimAndTick($orch, $task->id);

        $task->refresh();
        expect($task->status)->toBe('COMPLETED');
    });

    it('tick() leaves a QUEUED row untouched if not claimed first', function (): void {
        // tick() is a no-op on QUEUED — Phase 1 invariant. A caller that
        // forgets the claim must NOT see the row advance silently.
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->shouldNotReceive('complete');

        $orch = new Orchestrator(
            mockLlmForQueue($mock),
            new OrchestratorConfig(logger: new NullLogger()),
        );

        $task = $orch->start($this->agent->id, 'Should not run', maxSteps: 10);
        expect($task->status)->toBe('QUEUED');

        $orch->tick($task->id);

        $task->refresh();
        expect($task->status)->toBe('QUEUED');
    });

    it('tick() with OrchestratorConfig writes lease fields onto the row', function (): void {
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->andReturn(new LLMResponse('Done.', [], 5, 3, 'cmp_2'));
        $mock->allows('getProviderName')->andReturn('mock');
        $mock->allows('getModelName')->andReturn('mock-model');

        $orch = new Orchestrator(
            mockLlmForQueue($mock),
            new OrchestratorConfig(logger: new NullLogger()),
        );

        $task = $orch->start($this->agent->id, 'Browser-tick me', maxSteps: 10);

        // Mirrors TaskController::tick's claim + tick sequence: CAS-claim
        // inside a transaction writes the lease onto the row, then the
        // orchestrator's tick() takes the lease-aware config through to
        // TickPhaseRunner (the reaper must NOT see this row as an orphan
        // while the tick is in flight).
        $config = (new OrchestratorConfig())->withLease('user:42', 600);
        $claimed = Illuminate\Database\Capsule\Manager::connection()->transaction(
            function () use ($task): ?Task {
                $row = Task::where('id', $task->id)
                    ->where('status', 'QUEUED')
                    ->lockForUpdate()
                    ->first();
                if ($row === null) {
                    return null;
                }
                $row->status = 'RUNNING';
                $row->lease_owner = 'user:42';
                $row->lease_expires_at = Illuminate\Support\Carbon::now()->modify('+600 seconds');
                $row->save();

                return $row;
            },
        );
        expect($claimed)->not->toBeNull();

        // Lease is on the row while the tick is in flight.
        $midClaim = Task::find($claimed->id);
        expect($midClaim->status)->toBe('RUNNING')
            ->and($midClaim->lease_owner)->toBe('user:42')
            ->and($midClaim->lease_expires_at)->not->toBeNull();

        $orch->tick($claimed->id, $config);

        $fresh = Task::find($claimed->id);
        // The orchestrator's tick reaches COMPLETED; lease-clearing is the
        // caller's responsibility (TaskController::tick and ScheduledRunProcessor
        // both clear it once the row hits terminal/quiescent).
        expect($fresh->status)->toBe('COMPLETED');
        expect($fresh->lease_owner)->toBe('user:42');
    });
});
