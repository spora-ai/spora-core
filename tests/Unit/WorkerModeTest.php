<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\Task;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Fixtures\StubInputTool;

function makeOrchestratorForMode(
    DriverFactory $driverFactory,
    WorkerMode $workerMode,
    array $toolInstances = [],
): Orchestrator {
    return new Orchestrator(
        driverFactory: $driverFactory,
        toolInstances: $toolInstances,
        logger: new NullLogger(),
        workerMode: $workerMode,
    );
}

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

function seedAgentForMode(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('modetest@example.com', 'Password1!');

    $agent = Agent::create([
        'user_id' => $userId,
        'name' => 'Mode Test Agent',
        'llm_provider' => 'mock',
        'llm_model' => 'mock-model',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$agent->id, $userId];
}

// ---------------------------------------------------------------------------
// start() — worker mode variations
// ---------------------------------------------------------------------------

it('start in sync mode creates RUNNING task and dispatches tick synchronously', function (): void {
    [$agentId] = seedAgentForMode();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return $callCount === 1
            ? new LLMResponse(null, [], 5, 3, 'cmp_1')
            : new LLMResponse('Done.', [], 5, 3, 'cmp_2');
    });

    $orch = makeOrchestratorForMode(mockDriverFactoryForMode($mock), WorkerMode::Sync);
    $task = $orch->start($agentId, 'Hello sync', maxSteps: 10);

    expect($task->status)->toBe('COMPLETED')
        ->and($task->user_prompt)->toBe('Hello sync');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('start in cron mode creates QUEUED task without dispatching tick', function (): void {
    [$agentId] = seedAgentForMode();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->never();

    $orch = makeOrchestratorForMode(mockDriverFactoryForMode($mock), WorkerMode::Cron);
    $task = $orch->start($agentId, 'Hello cron', maxSteps: 10);

    expect($task->status)->toBe('QUEUED')
        ->and($task->user_prompt)->toBe('Hello cron');

    // No tick was dispatched — LLM should never be called.
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('start in worker mode creates QUEUED task without dispatching tick', function (): void {
    [$agentId] = seedAgentForMode();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->never();

    $orch = makeOrchestratorForMode(mockDriverFactoryForMode($mock), WorkerMode::Worker);
    $task = $orch->start($agentId, 'Hello worker', maxSteps: 10);

    expect($task->status)->toBe('QUEUED')
        ->and($task->user_prompt)->toBe('Hello worker');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// WorkerRunCommand — cron mode drain
// ---------------------------------------------------------------------------

it('WorkerRunCommand processes a single QUEUED task to completion', function (): void {
    [$agentId, $userId] = seedAgentForMode();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return new LLMResponse("Step {$callCount}", [], 5, 3, "cmp_{$callCount}");
    });

    $orch = makeOrchestratorForMode(mockDriverFactoryForMode($mock), WorkerMode::Cron, [new StubInputTool()]);

    // Create a pre-existing QUEUED task.
    $task = Task::create([
        'agent_id' => $agentId,
        'user_id' => $userId,
        'status' => 'QUEUED',
        'user_prompt' => 'Drain me',
        'step_count' => 0,
        'max_steps' => 10,
    ]);

    $output = new NullOutput();
    $input = new ArrayInput(['--limit' => '1']);

    $command = new Spora\Console\Commands\WorkerRunCommand($orch);
    $command->run($input, $output);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Step 1');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('WorkerRunCommand processes multiple QUEUED tasks in order', function (): void {
    [$agentId, $userId] = seedAgentForMode();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return new LLMResponse("Done {$callCount}", [], 5, 3, "cmp_{$callCount}");
    });

    $orch = makeOrchestratorForMode(mockDriverFactoryForMode($mock), WorkerMode::Cron, [new StubInputTool()]);

    // Create three QUEUED tasks.
    $task1 = Task::create(['agent_id' => $agentId, 'user_id' => $userId, 'status' => 'QUEUED', 'user_prompt' => 'Task 1', 'step_count' => 0, 'max_steps' => 10]);
    $task2 = Task::create(['agent_id' => $agentId, 'user_id' => $userId, 'status' => 'QUEUED', 'user_prompt' => 'Task 2', 'step_count' => 0, 'max_steps' => 10]);
    $task3 = Task::create(['agent_id' => $agentId, 'user_id' => $userId, 'status' => 'QUEUED', 'user_prompt' => 'Task 3', 'step_count' => 0, 'max_steps' => 10]);

    $output = new NullOutput();
    $input = new ArrayInput(['--limit' => '0']);

    $command = new Spora\Console\Commands\WorkerRunCommand($orch);
    $command->run($input, $output);

    $task1->refresh();
    $task2->refresh();
    $task3->refresh();

    expect($task1->status)->toBe('COMPLETED')
        ->and($task2->status)->toBe('COMPLETED')
        ->and($task3->status)->toBe('COMPLETED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('WorkerRunCommand exits cleanly when no QUEUED tasks exist', function (): void {
    [$agentId] = seedAgentForMode();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->never();

    $orch = makeOrchestratorForMode(mockDriverFactoryForMode($mock), WorkerMode::Cron);

    // No QUEUED tasks.

    $output = new BufferedOutput();
    $input = new ArrayInput([]);

    $command = new Spora\Console\Commands\WorkerRunCommand($orch);
    $result = $command->run($input, $output);

    expect($result)->toBe(Command::SUCCESS);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() with lockForUpdate — concurrent safety
// ---------------------------------------------------------------------------

it('tick is a no-op when task is QUEUED (only RUNNING tasks are processed)', function (): void {
    [$agentId, $userId] = seedAgentForMode();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->never();

    $orch = makeOrchestratorForMode(mockDriverFactoryForMode($mock), WorkerMode::Cron);

    $task = Task::create([
        'agent_id' => $agentId,
        'user_id' => $userId,
        'status' => 'QUEUED',
        'user_prompt' => 'Should not run',
        'step_count' => 0,
        'max_steps' => 10,
    ]);

    $orch->tick($task->id);

    $task->refresh();
    // Status must remain QUEUED — tick should be a no-op for non-RUNNING tasks.
    expect($task->status)->toBe('QUEUED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
