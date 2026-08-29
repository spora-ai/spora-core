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

function mockDriverFactoryForMode(LLMDriverInterface $driver): DriverFactory
{
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);
    return $factory;
}

describe('WorkerModeTest', function (): void {
    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = $this->authService->register('modetest@example.com', 'Password1!', 'Modetest');

        // Create a global LLM config for tests (tests mock the DriverFactory, so credentials don't matter)
        $config = LLMDriverConfiguration::create([
            'principal_id' => null,
            'name'          => 'Test Global Config',
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);

        $this->agent = Agent::create([
            'principal_id' => createUserPrincipalPublic($this->userId),
            'name'                 => 'Mode Test Agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);
    });

    it('start creates QUEUED task without dispatching tick', function (): void {
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->never();

        $orch = new Orchestrator(
            mockDriverFactoryForMode($mock),
            new OrchestratorConfig(
                logger: new NullLogger(),
            ),
        );

        $task = $orch->start($this->agent->id, 'Hello worker', maxSteps: 10);

        expect($task->status)->toBe('QUEUED')
            ->and($task->user_prompt)->toBe('Hello worker');
    });

    it('start creates QUEUED task that becomes terminal after tick', function (): void {
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->andReturn(new LLMResponse('Done.', [], 5, 3, 'cmp_1'));
        $mock->allows('getProviderName')->andReturn('mock');
        $mock->allows('getModelName')->andReturn('mock-model');

        $orch = new Orchestrator(
            mockDriverFactoryForMode($mock),
            new OrchestratorConfig(
                logger: new NullLogger(),
            ),
        );

        $task = $orch->start($this->agent->id, 'Hello worker', maxSteps: 10);
        expect($task->status)->toBe('QUEUED');

        claimAndTick($orch, $task->id);

        $task->refresh();
        expect($task->status)->toBe('COMPLETED');
    });

    it('tick is a no-op when task is QUEUED', function (): void {
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->never();

        $orch = new Orchestrator(
            mockDriverFactoryForMode($mock),
            new OrchestratorConfig(
                logger: new NullLogger(),
            ),
        );

        $task = Task::create([
            'agent_id' => $this->agent->id,
            'principal_id' => createUserPrincipalPublic($this->userId),
            'user_id'     => $this->userId,
            'status' => 'QUEUED',
            'user_prompt' => 'Should not run',
            'step_count' => 0,
            'max_steps' => 10,
        ]);

        $orch->tick($task->id);

        $task->refresh();
        expect($task->status)->toBe('QUEUED');
    });
});
