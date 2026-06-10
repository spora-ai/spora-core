<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\Orchestrator;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Console\Commands\TaskRunCommand;
use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ToolConfigService;
use Spora\Tools\EmailTool;
use Symfony\Component\Console\Tester\CommandTester;

// Helpers

defined('SQLITE_MEMORY') || define('SQLITE_MEMORY', ':memory:');
const PLUGINS_PATH = '/plugins';
const TEST_PASSWORD_RUN = 'Password1!';
const TEST_GLOBAL_CONFIG_NAME = 'Test Global Config';

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

// TaskRunCommand — task claiming

describe('TaskRunCommand — task claiming', function (): void {
    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = $this->authService->register('taskrun@example.com', TEST_PASSWORD_RUN, 'Taskrun');
        simulateLoggedInSession($this->userId, 'taskrun@example.com');

        $this->container = Mockery::mock(ContainerInterface::class);
        $this->container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => SQLITE_MEMORY,
            'worker_mode' => true,
            'llm_timeout' => 300,
        ]);
        $mockMercure = Mockery::mock(MercurePublisherInterface::class);
        $mockMercure->allows('publish')->andReturn(true);
        $mockMercure->allows('publishToUser')->andReturn(true);
        $this->container->allows('get')->with(NotificationService::class)->andReturn(
            Mockery::mock(NotificationService::class),
        );
        $this->container->allows('get')->with(LoggerInterface::class)->andReturn(
            Mockery::mock(LoggerInterface::class),
        );

        // Create a global LLM config for tests (tests mock the DriverFactory, so credentials don't matter)
        $this->llmConfig = LLMDriverConfiguration::create([
            'user_id'       => null,
            'name'          => TEST_GLOBAL_CONFIG_NAME,
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);
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
            ['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY],
            new Spora\Plugins\PluginLoader([BASE_PATH . PLUGINS_PATH]),
        );
        $db->bootDatabaseConnectionOnly();

        $container = Mockery::mock(ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => SQLITE_MEMORY,
        ]);
        $container->allows('get')->with(Database::class)->andReturn($db);
        $container->allows('get')->with(NotificationService::class)->andReturn(
            Mockery::mock(NotificationService::class),
        );
        $container->allows('get')->with(MercurePublisherInterface::class)->andReturn(
            Mockery::mock(MercurePublisherInterface::class),
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

    it('executes and requests LLMConfigService from the container', function (): void {
        $agent = Agent::create([
            'user_id'   => $this->userId,
            'name'      => 'TestAgentTester',
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
            ['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY],
            new Spora\Plugins\PluginLoader([BASE_PATH . PLUGINS_PATH]),
        );
        $db->bootDatabaseConnectionOnly();

        $textDriver = makeTextResponseDriver('Done');
        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($textDriver);

        $container = Mockery::mock(ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => SQLITE_MEMORY,
        ]);
        $container->allows('get')->with(Database::class)->andReturn($db);
        $container->allows('get')->with(DriverFactory::class)->andReturn($factory);
        $container->allows('get')->with('tool_instances')->andReturn([]);
        $notificationMock = Mockery::mock(NotificationService::class);
        $notificationMock->allows('notifyTaskCompleted')->andReturnNull();
        $container->allows('get')->with(NotificationService::class)->andReturn($notificationMock);

        $mockSecurity = Mockery::mock(Spora\Core\SecurityManagerInterface::class);
        $realConfigService = new LLMConfigService($mockSecurity, []);
        $container->shouldReceive('get')->with(LLMConfigService::class)->once()->andReturn($realConfigService);
        $container->allows('get')->with(ToolConfigService::class)->andReturn(Mockery::mock(ToolConfigService::class));
        $container->allows('get')->with(MercurePublisherInterface::class)->andReturn(Mockery::mock(MercurePublisherInterface::class));
        $container->allows('get')->with(LoggerInterface::class)->andReturn(Mockery::mock(LoggerInterface::class));

        $mercure = Mockery::mock(MercurePublisherInterface::class);
        $mercure->allows('publishUpdate')->andReturnNull();

        $command = new TaskRunCommand($db, $container, $mercure);
        $command->setName('task:run');
        $tester = new CommandTester($command);

        $tester->execute(['taskId' => $task->id]);

        if ($tester->getStatusCode() !== 0) {
            throw new RuntimeException($tester->getDisplay());
        } expect($tester->getStatusCode())->toBe(0);

        $task->refresh();
        expect($task->status)->toBe('COMPLETED');
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
            ['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY],
            new Spora\Plugins\PluginLoader([BASE_PATH . PLUGINS_PATH]),
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

        $container = Mockery::mock(ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => SQLITE_MEMORY,
        ]);
        $container->allows('get')->with(Database::class)->andReturn($db);
        $container->allows('get')->with(DriverFactory::class)->andReturn($nullFactory);
        $container->allows('get')->with('tool_instances')->andReturn([]);
        $container->allows('get')->with(NotificationService::class)->andReturn(
            Mockery::mock(NotificationService::class),
        );
        $container->allows('get')->with(MercurePublisherInterface::class)->andReturn(
            Mockery::mock(MercurePublisherInterface::class),
        );

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

// TaskRunCommand — task processing via Orchestrator

describe('TaskRunCommand — orchestrator integration', function (): void {
    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = $this->authService->register('taskrun3@example.com', TEST_PASSWORD_RUN, 'Taskrun3');
        simulateLoggedInSession($this->userId, 'taskrun3@example.com');

        // Create a global LLM config for tests (tests mock the DriverFactory, so credentials don't matter)
        $this->llmConfig = LLMDriverConfiguration::create([
            'user_id'       => null,
            'name'          => TEST_GLOBAL_CONFIG_NAME,
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);
    });

    it('completes a task when the LLM returns a text response', function (): void {
        $agent = Agent::create([
            'user_id'              => $this->userId,
            'name'                 => 'TestAgent3',
            'llm_driver_config_id' => $this->llmConfig->id,
            'max_steps'            => 10,
            'is_active'           => true,
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
            ['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY],
            new Spora\Plugins\PluginLoader([BASE_PATH . PLUGINS_PATH]),
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

        $container = Mockery::mock(ContainerInterface::class);
        $container->allows('get')->with('config')->andReturn([
            'db_driver' => 'sqlite',
            'db_path' => SQLITE_MEMORY,
        ]);
        $container->allows('get')->with(Database::class)->andReturn($db);
        $container->allows('get')->with(DriverFactory::class)->andReturn($factory);
        $container->allows('get')->with('tool_instances')->andReturn([]);
        $container->allows('get')->with(NotificationService::class)->andReturn(
            Mockery::mock(NotificationService::class),
        );

        $orchestrator = new Orchestrator(
            $factory,
            new OrchestratorConfig(),
        );

        // Run one tick — task should complete.
        $orchestrator->tick($task->id);
        $task->refresh();

        expect($task->status)->toBe('COMPLETED')
            ->and($task->final_response)->toBe('Hello! How can I help?');
    });

    it('marks a task FAILED when the LLM throws', function (): void {
        // Create a global LLM config in the same DB the agent will use
        $llmConfig = LLMDriverConfiguration::create([
            'user_id'       => null,
            'name'          => TEST_GLOBAL_CONFIG_NAME,
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);

        $agent = Agent::create([
            'user_id'              => $this->userId,
            'name'                 => 'TestAgent4',
            'llm_driver_config_id' => $llmConfig->id,
            'max_steps'            => 10,
            'is_active'            => true,
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
            ['db_driver' => 'sqlite', 'db_path' => SQLITE_MEMORY],
            new Spora\Plugins\PluginLoader([BASE_PATH . PLUGINS_PATH]),
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

// TaskRunCommand — tool config injection

describe('TaskRunCommand — tool config injection', function (): void {
    beforeEach(function (): void {
        $this->authService = bootAuthLayer();
        $this->userId = $this->authService->register('toolconfig@example.com', TEST_PASSWORD_RUN, 'ToolConfig');

        // Create a global LLM config
        $this->llmConfig = LLMDriverConfiguration::create([
            'user_id'       => null,
            'name'          => TEST_GLOBAL_CONFIG_NAME,
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);

        // Set up ToolConfigService with EmailTool
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->security = new SecurityManager($key);
        $this->toolConfigService = new ToolConfigService(
            $this->security,
            new Monolog\Logger('test'),
            [EmailTool::class],
        );

        // Set up LLMConfigService (required by Orchestrator.getTemperatureFromSettings)
        $this->llmConfigService = new LLMConfigService(
            $this->security,
            [
                Spora\Drivers\OpenAICompatibleDriver::class,
                Spora\Drivers\AnthropicCompatibleDriver::class,
            ],
        );

        // Set up LLM config for the agent
        $this->agent = Agent::create([
            'user_id'              => $this->userId,
            'name'                 => 'ToolConfigAgent',
            'llm_driver_config_id' => $this->llmConfig->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);

        // Enable EmailTool for the agent
        AgentTool::create([
            'agent_id'   => $this->agent->id,
            'tool_class' => EmailTool::class,
            'tool_name'  => 'email',
        ]);
    });

    it('buildToolDefinitions includes exposeToLlm settings in tool description when configured', function (): void {
        // Store expose_to_llm settings via ToolConfigService
        $this->toolConfigService->putAgentOverride(EmailTool::class, $this->agent->id, [
            'core.smtp.from'              => 'agent@example.com',
            'core.smtp.allowed_recipients' => 'alice@example.com, bob@example.com',
        ]);

        // Use reflection to call private buildToolDefinitions
        $emailTool = new EmailTool(
            $this->toolConfigService,
            Mockery::mock(Spora\Services\ImapClientInterface::class),
        );
        $capturingDriver = Mockery::mock(LLMDriverInterface::class);
        $capturingDriver->allows('complete')->andReturn(new LLMResponse(
            content: 'Done.',
            toolCalls: [],
            inputTokens: 10,
            outputTokens: 5,
            completionId: 'test',
        ));
        $capturingDriver->allows('getProviderName')->andReturn('mock');
        $capturingDriver->allows('getModelName')->andReturn('mock-model');

        $capturingFactory = Mockery::mock(DriverFactory::class);
        $capturingFactory->allows('makeFromAgent')->andReturn($capturingDriver);

        $mockNotification = Mockery::mock(NotificationService::class);
        $mockNotification->allows('notifyTaskCompleted')->andReturnNull();
        $mockNotification->allows('notifyTaskFailed')->andReturnNull();

        $orchestrator = new Orchestrator(
            driverFactory: $capturingFactory,
            toolInstances: [$emailTool],
            logger: null,
            workerMode: WorkerMode::Sync,
            notificationService: $mockNotification,
            mercure: Mockery::mock(MercurePublisherInterface::class),
            toolConfigService: $this->toolConfigService,
            llmConfigService: $this->llmConfigService,
        );

        $task = Task::create([
            'agent_id'    => $this->agent->id,
            'user_id'     => $this->userId,
            'status'      => 'RUNNING',
            'user_prompt' => 'What can you do?',
            'max_steps'   => 10,
            'step_count'  => 0,
        ]);

        $orchestrator->tick($task->id);

        // Verify the email tool description includes the config block
        $reflection = new ReflectionClass($orchestrator);
        $method = $reflection->getMethod('buildToolDefinitions');
        $defs = $method->invoke($orchestrator, [EmailTool::class], $this->agent->id, $this->userId);

        $emailDef = collect($defs)->firstWhere('function.name', 'email');
        expect($emailDef)->not->toBeNull();
        expect($emailDef['function']['description'])->toContain('[Effective Configuration]');
        expect($emailDef['function']['description'])->toContain('From Address: agent@example.com');
        expect($emailDef['function']['description'])->toContain('Allowed Recipients: alice@example.com, bob@example.com');
    })->afterEach(fn() => Database::resetBootState());

    it('buildToolDefinitions shows (not configured) for unset exposeToLlm settings', function (): void {
        // No settings stored — all exposeToLlm fields should show as (not configured)

        $emailTool = new EmailTool(
            $this->toolConfigService,
            Mockery::mock(Spora\Services\ImapClientInterface::class),
        );
        $capturingDriver = Mockery::mock(LLMDriverInterface::class);
        $capturingDriver->allows('complete')->andReturn(new LLMResponse(
            content: 'Done.',
            toolCalls: [],
            inputTokens: 10,
            outputTokens: 5,
            completionId: 'test',
        ));
        $capturingDriver->allows('getProviderName')->andReturn('mock');
        $capturingDriver->allows('getModelName')->andReturn('mock-model');

        $capturingFactory = Mockery::mock(DriverFactory::class);
        $capturingFactory->allows('makeFromAgent')->andReturn($capturingDriver);

        $mockNotification = Mockery::mock(NotificationService::class);
        $mockNotification->allows('notifyTaskCompleted')->andReturnNull();
        $mockNotification->allows('notifyTaskFailed')->andReturnNull();

        $orchestrator = new Orchestrator(
            driverFactory: $capturingFactory,
            toolInstances: [$emailTool],
            logger: null,
            workerMode: WorkerMode::Sync,
            notificationService: $mockNotification,
            mercure: Mockery::mock(MercurePublisherInterface::class),
            toolConfigService: $this->toolConfigService,
            llmConfigService: $this->llmConfigService,
        );

        $reflection = new ReflectionClass($orchestrator);
        $method = $reflection->getMethod('buildToolDefinitions');
        $defs = $method->invoke($orchestrator, [EmailTool::class], $this->agent->id, $this->userId);

        $emailDef = collect($defs)->firstWhere('function.name', 'email');
        expect($emailDef)->not->toBeNull();
        expect($emailDef['function']['description'])->toContain('[Effective Configuration]');
        expect($emailDef['function']['description'])->toContain('From Address: (not configured)');
        expect($emailDef['function']['description'])->toContain('Allowed Recipients: (not configured)');
    })->afterEach(fn() => Database::resetBootState());
});
