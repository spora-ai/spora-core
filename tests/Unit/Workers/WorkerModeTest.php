<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;

function mockLlmForMode(LLMResponse $response): LLMDriverInterface
{
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturn($response);
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');
    return $mock;
}

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
            'user_id'       => null,
            'name'          => 'Test Global Config',
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);

        $this->agent = Agent::create([
            'user_id'              => $this->userId,
            'name'                 => 'Mode Test Agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);
    });

    it('start in sync mode creates RUNNING task and dispatches tick synchronously', function (): void {
        $mock = mockLlmForMode(new LLMResponse('Done.', [], 5, 3, 'cmp_1'));

        $orch = new Orchestrator(
            mockDriverFactoryForMode($mock),
            new OrchestratorConfig(
                logger: new NullLogger(),
                workerMode: WorkerMode::Sync,
            ),
        );

        $task = $orch->start($this->agent->id, 'Hello sync', maxSteps: 10);

        expect($task->status)->toBe('COMPLETED')
            ->and($task->user_prompt)->toBe('Hello sync');
    });

    it('start in worker mode creates QUEUED task without dispatching tick', function (): void {
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->never();

        $orch = new Orchestrator(
            mockDriverFactoryForMode($mock),
            new OrchestratorConfig(
                logger: new NullLogger(),
                workerMode: WorkerMode::Worker,
            ),
        );

        $task = $orch->start($this->agent->id, 'Hello worker', maxSteps: 10);

        expect($task->status)->toBe('QUEUED')
            ->and($task->user_prompt)->toBe('Hello worker');
    });

    it('tick is a no-op when task is QUEUED', function (): void {
        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->never();

        $orch = new Orchestrator(
            mockDriverFactoryForMode($mock),
            new OrchestratorConfig(
                logger: new NullLogger(),
                workerMode: WorkerMode::Worker,
            ),
        );

        $task = Task::create([
            'agent_id' => $this->agent->id,
            'user_id' => $this->userId,
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
