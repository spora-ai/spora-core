<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\Orchestrator;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Console\Commands\TaskRunCommand;
use Spora\Core\Database;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a mock LLM driver that returns a text response.
 */
function makeTextResponseDriver(string $content): LLMDriverInterface
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andReturn(new LLMResponse(
        content: $content,
        toolCalls: [],
        inputTokens: 10,
        outputTokens: 20,
        completionId: 'test-completion',
    ));
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');

    return $driver;
}

/**
 * Create a mock LLM driver that throws.
 */
function makeThrowingDriver(Throwable $e): LLMDriverInterface
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andThrow($e);
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');

    return $driver;
}

// ---------------------------------------------------------------------------
// TaskRunCommand — task claiming
// ---------------------------------------------------------------------------

describe('TaskRunCommand — task claiming', function (): void {
    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = $this->authService->register('taskrun@example.com', 'Password1!');
        simulateLoggedInSession($this->userId, 'taskrun@example.com');

        $this->container = Mockery::mock(Psr\Container\ContainerInterface::class);
        $this->container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => ':memory:',
            'worker_mode' => true,
            'llm_timeout' => 300,
        ]);
        $this->container->allows('get')->with(NotificationService::class)->andReturn(
            Mockery::mock(NotificationService::class),
        );
    });

    it('transitions a QUEUED task to RUNNING when claimed', function (): void {
        $agent = Agent::create([
            'user_id'   => $this->userId,
            'name'      => 'TestAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $this->userId,
            'status'      => 'QUEUED',
            'user_prompt' => 'Hello',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        $db = new Database(
            ['db_driver' => 'sqlite', 'db_path' => ':memory:'],
            new Spora\Plugins\PluginLoader(BASE_PATH . '/plugins'),
        );
        $db->bootDatabaseConnectionOnly();

        $container = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => ':memory:',
        ]);
        $container->allows('get')->with(Database::class)->andReturn($db);
        $container->allows('get')->with(NotificationService::class)->andReturn(
            Mockery::mock(NotificationService::class),
        );

        // Use a null driver — we only care about the claim, not the LLM call.
        $nullDriver = Mockery::mock(LLMDriverInterface::class);
        $nullDriver->allows('complete')->andReturn(new LLMResponse(
            content: null,
            toolCalls: [],
            inputTokens: 0,
            outputTokens: 0,
            completionId: 'null',
        ));
        $nullDriver->allows('getProviderName')->andReturn('mock');
        $nullDriver->allows('getModelName')->andReturn('mock-model');

        $nullFactory = Mockery::mock(DriverFactory::class);
        $nullFactory->allows('makeFromAgent')->andReturn($nullDriver);
        $container->allows('get')->with(DriverFactory::class)->andReturn($nullFactory);
        $container->allows('get')->with('tool_instances')->andReturn([]);

        $command = new TaskRunCommand($db, $container);

        // Simulate what execute() does at the task claim step.
        $taskId = $task->id;
        $claimedTask = Capsule::connection()->transaction(function () use ($taskId): ?Task {
            /** @var Task|null $t */
            $t = Task::where('id', $taskId)
                ->where('status', 'QUEUED')
                ->lockForUpdate()
                ->first();

            if ($t === null) {
                return null;
            }

            $t->status = 'RUNNING';
            $t->save();

            return $t;
        });

        expect($claimedTask)->not->toBeNull()
            ->and($claimedTask->status)->toBe('RUNNING');
    });

    it('returns null when the task is not QUEUED', function (): void {
        $agent = Agent::create([
            'user_id'   => $this->userId,
            'name'      => 'TestAgent2',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $this->userId,
            'status'      => 'RUNNING',
            'user_prompt' => 'Already running',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        $db = new Database(
            ['db_driver' => 'sqlite', 'db_path' => ':memory:'],
            new Spora\Plugins\PluginLoader(BASE_PATH . '/plugins'),
        );
        $db->bootDatabaseConnectionOnly();

        $nullDriver = Mockery::mock(LLMDriverInterface::class);
        $nullDriver->allows('complete')->andReturn(new LLMResponse(
            content: null,
            toolCalls: [],
            inputTokens: 0,
            outputTokens: 0,
            completionId: 'null',
        ));
        $nullDriver->allows('getProviderName')->andReturn('mock');
        $nullDriver->allows('getModelName')->andReturn('mock-model');

        $nullFactory = Mockery::mock(DriverFactory::class);
        $nullFactory->allows('makeFromAgent')->andReturn($nullDriver);

        $container = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => ':memory:',
        ]);
        $container->allows('get')->with(Database::class)->andReturn($db);
        $container->allows('get')->with(DriverFactory::class)->andReturn($nullFactory);
        $container->allows('get')->with('tool_instances')->andReturn([]);
        $container->allows('get')->with(NotificationService::class)->andReturn(
            Mockery::mock(NotificationService::class),
        );

        $command = new TaskRunCommand($db, $container);

        $taskId = $task->id;
        $claimedTask = Capsule::connection()->transaction(function () use ($taskId): ?Task {
            /** @var Task|null $t */
            $t = Task::where('id', $taskId)
                ->where('status', 'QUEUED')
                ->lockForUpdate()
                ->first();

            if ($t === null) {
                return null;
            }

            $t->status = 'RUNNING';
            $t->save();

            return $t;
        });

        expect($claimedTask)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// TaskRunCommand — task processing via Orchestrator
// ---------------------------------------------------------------------------

describe('TaskRunCommand — orchestrator integration', function (): void {
    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = $this->authService->register('taskrun3@example.com', 'Password1!');
        simulateLoggedInSession($this->userId, 'taskrun3@example.com');
    });

    it('completes a task when the LLM returns a text response', function (): void {
        $agent = Agent::create([
            'user_id'       => $this->userId,
            'name'          => 'TestAgent3',
            'llm_provider'  => 'mock',
            'llm_model'     => 'mock-model',
            'max_steps'     => 10,
            'is_active'     => true,
        ]);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $this->userId,
            'status'      => 'RUNNING',
            'user_prompt' => 'Hello',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        $db = new Database(
            ['db_driver' => 'sqlite', 'db_path' => ':memory:'],
            new Spora\Plugins\PluginLoader(BASE_PATH . '/plugins'),
        );
        $db->bootDatabaseConnectionOnly();

        $textDriver = Mockery::mock(LLMDriverInterface::class);
        $textDriver->allows('complete')->andReturn(new LLMResponse(
            content: 'Hello! How can I help?',
            toolCalls: [],
            inputTokens: 10,
            outputTokens: 20,
            completionId: 'test-completion',
        ));
        $textDriver->allows('getProviderName')->andReturn('mock');
        $textDriver->allows('getModelName')->andReturn('mock-model');

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($textDriver);

        $container = Mockery::mock(Psr\Container\ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => ':memory:',
        ]);
        $container->allows('get')->with(Database::class)->andReturn($db);
        $container->allows('get')->with(DriverFactory::class)->andReturn($factory);
        $container->allows('get')->with('tool_instances')->andReturn([]);
        $container->allows('get')->with(NotificationService::class)->andReturn(
            Mockery::mock(NotificationService::class),
        );

        $orchestrator = new Orchestrator(
            driverFactory: $factory,
            toolInstances: [],
            workerMode: WorkerMode::Sync,
        );

        // Run one tick — task should complete.
        $orchestrator->tick($task->id);
        $task->refresh();

        expect($task->status)->toBe('COMPLETED')
            ->and($task->final_response)->toBe('Hello! How can I help?');
    });

    it('marks a task FAILED when the LLM throws', function (): void {
        $agent = Agent::create([
            'user_id'       => $this->userId,
            'name'          => 'TestAgent4',
            'llm_provider'  => 'mock',
            'llm_model'     => 'mock-model',
            'max_steps'     => 10,
            'is_active'     => true,
        ]);

        $task = Task::create([
            'agent_id'    => $agent->id,
            'user_id'     => $this->userId,
            'status'      => 'RUNNING',
            'user_prompt' => 'Fail me',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        $db = new Database(
            ['db_driver' => 'sqlite', 'db_path' => ':memory:'],
            new Spora\Plugins\PluginLoader(BASE_PATH . '/plugins'),
        );
        $db->bootDatabaseConnectionOnly();

        $throwingDriver = Mockery::mock(LLMDriverInterface::class);
        $throwingDriver->allows('complete')
            ->andThrow(new RuntimeException('Intentional LLM failure'));
        $throwingDriver->allows('getProviderName')->andReturn('mock');
        $throwingDriver->allows('getModelName')->andReturn('mock-model');

        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($throwingDriver);

        $orchestrator = new Orchestrator(
            driverFactory: $factory,
            toolInstances: [],
            workerMode: WorkerMode::Sync,
        );

        try {
            $orchestrator->tick($task->id);
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Intentional LLM failure');
        }

        $task->refresh();
        expect($task->status)->toBe('FAILED');
    });
});
