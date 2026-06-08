<?php

declare(strict_types=1);

use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\Exceptions\LlmConfigurationMissingException;
use Spora\Agents\Exceptions\TaskStateMissingException;
use Spora\Agents\Exceptions\ToolContractException;
use Spora\Agents\Exceptions\ToolNotEnabledException;
use Spora\Agents\Exceptions\ToolNotRegisteredException;
use Spora\Agents\Orchestrator;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a no-op DriverFactory that returns a mock LLM driver.
 */
function makeBareDriverFactory(): DriverFactory
{
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturn(new LLMResponse('Ok', [], 1, 1, 'cmp_x'));
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    return $factory;
}

/**
 * Create a minimal Orchestrator backed by a no-op mock LLM driver.
 */
function makeBareOrchestrator(): Orchestrator
{
    return new Orchestrator(
        driverFactory: makeBareDriverFactory(),
        llmConfigService: null,
        toolInstances: [],
        logger: null,
        workerMode: WorkerMode::Sync,
    );
}

/**
 * Create an agent + a single RUNNING task. Returns the task id.
 */
function seedRunningTask(): int
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('excs@example.com', TEST_PASSWORD, 'Excs');

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Exception Test Agent',
        'llm_driver_config_id' => null,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'in progress',
        'step_count'  => 0,
        'max_steps'   => 5,
    ]);

    return $task->id;
}

// ---------------------------------------------------------------------------
// InvalidTaskTransitionException — continue() with non-terminal task
// ---------------------------------------------------------------------------

it('continue() throws InvalidTaskTransitionException when task is not COMPLETED or FAILED', function (): void {
    $taskId = seedRunningTask();
    $orch   = makeBareOrchestrator();

    expect(fn() => $orch->continue($taskId, 'new prompt'))
        ->toThrow(InvalidTaskTransitionException::class, 'Can only continue completed or failed tasks.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// TaskStateMissingException — defensive throw inside resume()/reject()
// ---------------------------------------------------------------------------
//
// The post-transaction `!$task instanceof Task || !$state instanceof AgentState`
// guard inside loadTaskAndStateForResume() is defensive code: under normal
// operation the DB transaction always assigns both refs. The only externally
// observable contract is that the exception extends RuntimeException, so the
// resume()/reject() outer `catch (Throwable $e)` path can re-throw it. We
// verify that contract here.

it('TaskStateMissingException extends RuntimeException so resume()/reject() can re-throw it', function (): void {
    expect(get_parent_class(TaskStateMissingException::class))->toBe(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// ToolNotEnabledException — handleToolCalls() rejects a non-enabled tool
// ---------------------------------------------------------------------------

it('handleToolCalls throws ToolNotEnabledException when LLM calls a tool that is not enabled for the agent', function (): void {
    $authService = bootAuthLayer();
    $userId      = $authService->register('tool-exc@example.com', TEST_PASSWORD, 'Tool');

    $config = Spora\Models\LLMDriverConfiguration::create([
        'user_id'             => null,
        'name'                => 'Test Global Config',
        'driver_class'        => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'            => json_encode(['api_key' => 'test']),
        'is_global'           => true,
        'is_default'          => true,
        'context_window'      => 128000,
        'max_tokens_output'   => 4096,
    ]);
    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Tool Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturn(
        new LLMResponse(null, [new DriverToolCall('call_unauth', 'stub_input', [])], 5, 3, 'cmp_1'),
    );
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    // The agent is intentionally NOT given access to StubInputTool — the
    // handleToolCalls() body will throw ToolNotEnabledException, which is
    // caught by the surrounding try/catch and turned into a System Error row.
    $orch = new Orchestrator(
        driverFactory: $factory,
        llmConfigService: null,
        toolInstances: [new Tests\Fixtures\StubInputTool()],
        logger: null,
        workerMode: WorkerMode::Sync,
    );
    $task = $orch->start($agent->id, 'Tool not enabled test', maxSteps: 3);

    $errorHistory = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_unauth')
        ->first();
    expect($errorHistory)->not->toBeNull()
        ->and($errorHistory->content)->toContain("The LLM attempted to call tool 'stub_input' which is not enabled for this agent.");
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// ToolContractException — tool class does not use HasOperations trait
// ---------------------------------------------------------------------------

it('resolveRequiresApproval throws ToolContractException for a tool class without the HasOperations trait', function (): void {
    $orch = makeBareOrchestrator();

    $tool = new class implements ToolInterface {
        public function name(): string
        {
            return 'plain_tool';
        }

        public function description(): string
        {
            return 'Plain tool with no HasOperations';
        }

        public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
        {
            return new ToolResult(true, 'ok');
        }

        public function describeAction(array $arguments): string
        {
            return 'Will run plain tool.';
        }

        public function getParametersSchema(): array
        {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }
    };

    $ref = new ReflectionMethod(Orchestrator::class, 'resolveRequiresApproval');
    $ref->setAccessible(true);

    $toolClass = $tool::class;
    expect(fn() => $ref->invoke($orch, $tool, $toolClass, 1, []))
        ->toThrow(ToolContractException::class, "Tool '{$toolClass}' does not use HasOperations trait.");
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// ToolNotRegisteredException — resolveToolByName cannot find the name
// ---------------------------------------------------------------------------

it('resolveToolByName throws ToolNotRegisteredException for an unknown tool name', function (): void {
    $orch = makeBareOrchestrator();

    $ref = new ReflectionMethod(Orchestrator::class, 'resolveToolByName');
    $ref->setAccessible(true);

    expect(fn() => $ref->invoke($orch, 'definitely_not_registered'))
        ->toThrow(ToolNotRegisteredException::class, "No tool registered with name 'definitely_not_registered'.");
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// LlmConfigurationMissingException — start() with no config and no global default
// ---------------------------------------------------------------------------

it('start() throws LlmConfigurationMissingException when no config and no global default exist', function (): void {
    $authService = bootAuthLayer();
    $userId      = $authService->register('llm-exc@example.com', TEST_PASSWORD, 'Llm');
    $agent       = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'No Config Agent',
        'llm_driver_config_id' => null,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    $orch = makeBareOrchestrator();

    expect(fn() => $orch->start($agent->id, 'Hello', maxSteps: 5))
        ->toThrow(LlmConfigurationMissingException::class, 'No LLM configuration set for this agent. Set a preferred config or ensure a global default exists.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
