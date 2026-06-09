<?php

declare(strict_types=1);

use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\Orchestrator;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Models\UserPreference;
use Spora\Plugins\PluginInterface;
use Spora\Plugins\PluginLoader;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\ToolCallSerializer;
use Spora\Tools\AgentMemoryTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\GlobalMemoryTool;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Tests\Fixtures\SpyAgentIdInputTool;
use Tests\Fixtures\StubAutoApproveOutputTool;
use Tests\Fixtures\StubFailingTool;
use Tests\Fixtures\StubInputTool;
use Tests\Fixtures\StubOutputTool;
use Tests\Fixtures\StubOutputToolWithSchema;
use Tests\Fixtures\ThrowingTool;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');
const OPENAI_COMPATIBLE_DRIVER = 'Spora\Drivers\OpenAICompatibleDriver';
const USER_PREFERRED_CONFIG_NAME = 'User Preferred Config';
const PROMPT_ORIGINAL = 'Original prompt';
const PROMPT_CONTINUED = 'Continued prompt';
const VALIDATION_ERROR = 'Validation Error';
const PROMPT_HELLO = 'Hello!';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeOrchestrator(
    DriverFactory $driverFactory,
    array $toolInstances = [],
    ?Psr\Log\LoggerInterface $logger = null,
    WorkerMode $workerMode = WorkerMode::Sync,
): Orchestrator {
    return new Orchestrator(
        driverFactory: $driverFactory,
        llmConfigService: null,
        toolInstances: $toolInstances,
        logger: $logger,
        workerMode: $workerMode,
    );
}

/**
 * Create a mock LLMDriverInterface that returns a fixed LLMResponse.
 */
function mockLlm(LLMResponse $response): LLMDriverInterface
{
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturn($response);
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');

    return $mock;
}

/**
 * Wrap a mock LLMDriverInterface in a DriverFactory stub that always returns it.
 */
function mockDriverFactory(LLMDriverInterface $driver): DriverFactory
{
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    return $factory;
}

/**
 * Boot DB and create an agent + user, returning [$agentId, $userId].
 */
function seedAgent(): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('orch@example.com', TEST_PASSWORD, 'Orch');

    // Create a global LLM config as default (tests mock the DriverFactory, so credentials don't matter)
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

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Test Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    return [$agent->id, $userId];
}

/**
 * Marks the given tool instances as enabled for the agent in the database.
 */
function enableToolsForAgent(int $agentId, array $toolInstances): void
{
    foreach ($toolInstances as $instance) {
        AgentTool::insert([
            'agent_id'   => $agentId,
            'tool_class' => get_class($instance),
            'tool_name'  => 'test_tool',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

// ---------------------------------------------------------------------------
// start() tests
// ---------------------------------------------------------------------------

it('start creates a RUNNING Task and seeds user history row', function (): void {
    [$agentId] = seedAgent();

    $llm = mockLlm(new LLMResponse(
        content: 'Done.',
        toolCalls: [],
        inputTokens: 10,
        outputTokens: 5,
        completionId: 'cmp_1',
    ));

    $orch = makeOrchestrator(mockDriverFactory($llm));
    $task = $orch->start($agentId, PROMPT_HELLO, maxSteps: 5);

    expect($task->status)->toBe('COMPLETED')      // tick ran synchronously
        ->and($task->user_prompt)->toBe(PROMPT_HELLO)
        ->and($task->max_steps)->toBe(5);

    $history = TaskHistory::where('task_id', $task->id)->orderBy('sequence')->get();
    expect($history->first()->role)->toBe('user')
        ->and($history->first()->content)->toBe(PROMPT_HELLO);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — text response path
// ---------------------------------------------------------------------------

it('tick marks task COMPLETED when LLM returns text', function (): void {
    [$agentId] = seedAgent();

    $llm  = mockLlm(new LLMResponse('All done!', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Do something',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Do something']);

    $orch->tick($task->id);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('All done!');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('tick is a no-op when task is not RUNNING', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->never();

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'COMPLETED',
        'user_prompt' => 'x',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $orch->tick($task->id); // should not call LLM
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — single InputTool path
// ---------------------------------------------------------------------------

it('InputTool path increments step_count once per LLM turn', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_1', 'stub_input', [])], 10, 5, 'cmp_1');
        }
        return new LLMResponse('Done via input tool.', [], 10, 5, 'cmp_2');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Run input tool', maxSteps: 10);

    $task->refresh();

    // 2 LLM turns: one for the tool call, one for the final text response.
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2)
        ->and($task->final_response)->toBe('Done via input tool.');

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->tool_type)->toBe('input')
        ->and($toolCallRecord->result_content)->toBe('input_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — parallel tool calls (Fix #1)
// ---------------------------------------------------------------------------

it('two parallel InputTools are both executed and step_count is 2', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            // LLM fires two tools simultaneously in one response.
            return new LLMResponse(null, [
                new DriverToolCall('call_a', 'stub_input', []),
                new DriverToolCall('call_b', 'stub_input', []),
            ], 10, 5, 'cmp_1');
        }
        return new LLMResponse('Both done.', [], 10, 5, 'cmp_2');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Parallel inputs', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2);

    $toolCallRecords = ToolCallModel::where('task_id', $task->id)->get();
    expect($toolCallRecords)->toHaveCount(2);
    expect($toolCallRecords->every(fn($r) => $r->status === 'APPROVED'))->toBeTrue();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('N parallel InputTools in one LLM turn increment step_count by 1, not N', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            // LLM fires 10 tools simultaneously — must still count as a single step.
            return new LLMResponse(null, array_map(
                static fn(int $i) => new DriverToolCall("call_{$i}", 'stub_input', []),
                range(1, 10),
            ), 100, 50, 'cmp_1');
        }
        return new LLMResponse('All done.', [], 10, 5, 'cmp_2');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    // With the old bug: 10 tools × step_count++ would hit max_steps=10 immediately and FAIL.
    $task = $orch->start($agentId, 'Parallel overload', maxSteps: 10);

    $task->refresh();
    // 2 LLM turns (not 10): tick 1 runs all 10 tools, tick 2 gets the final response.
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2);

    expect(ToolCallModel::where('task_id', $task->id)->count())->toBe(10);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('parallel batch with one auto-execute and one requiring approval pauses with correct pending batch', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(
        new LLMResponse(null, [
            new DriverToolCall('call_input', 'stub_input', []),
            new DriverToolCall('call_output', 'stub_output', ['key' => 'val']),
        ], 10, 5, 'cmp_1'),
    );

    $tools = [new StubInputTool(), new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Mixed parallel', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    $state = AgentState::fromJson($task->pending_state);
    // Only the OutputTool that requires approval should be in pending_tool_calls.
    expect($state->pendingToolCalls)->toHaveCount(1);
    expect($state->pendingToolCalls[0]->toolName)->toBe('stub_output');

    // InputTool was already executed — step_count reflects it.
    expect($task->step_count)->toBe(1);

    $records = ToolCallModel::where('task_id', $task->id)->get();
    expect($records)->toHaveCount(2);
    $approved = $records->filter(fn($r) => $r->status === 'APPROVED');
    $pending  = $records->filter(fn($r) => $r->status === 'PENDING_APPROVAL');
    expect($approved)->toHaveCount(1);
    expect($pending)->toHaveCount(1);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('single assistant history row carries both tool calls when LLM fires two in parallel', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? new LLMResponse(null, [
                new DriverToolCall('call_x', 'stub_input', []),
                new DriverToolCall('call_y', 'stub_input', []),
            ], 10, 5, 'cmp_1')
            : new LLMResponse('done', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Parallel', maxSteps: 10);

    $assistantRow = TaskHistory::where('task_id', $task->id)
        ->where('role', 'assistant')
        ->whereNotNull('tool_call_payload')
        ->first();

    expect($assistantRow)->not()->toBeNull();

    $payload = json_decode($assistantRow->tool_call_payload, true);
    // tool_call_payload is now a JSON array, one entry per tool call.
    expect($payload)->toHaveCount(2);
    expect($payload[0]['id'])->toBe('call_x');
    expect($payload[1]['id'])->toBe('call_y');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — OutputTool (requires approval) path
// ---------------------------------------------------------------------------

it('OutputTool requiring approval pauses task as PENDING_APPROVAL and serializes AgentState', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(
        new LLMResponse(null, [new DriverToolCall('call_out', 'stub_output', ['key' => 'val'])], 10, 5, 'cmp_1'),
    );

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Run output tool', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL')
        ->and($task->pending_state)->not->toBeNull();

    $state = AgentState::fromJson($task->pending_state);
    expect($state->pendingToolCalls[0]->toolName)->toBe('stub_output')
        ->and($state->pendingToolCalls[0]->arguments)->toBe(['key' => 'val']);

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('PENDING_APPROVAL')
        ->and($toolCallRecord->tool_type)->toBe('output');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — OutputTool auto-approved (class attribute)
// ---------------------------------------------------------------------------

it('OutputTool with requiresApproval=false executes immediately', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_auto', 'stub_auto_output', [])], 5, 3, 'cmp_1')
            : new LLMResponse('Auto done.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubAutoApproveOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Auto approve', maxSteps: 10);

    $task->refresh();
    // 2 LLM turns: one for the tool, one for the final text.
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2);

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->result_content)->toBe('auto_output_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// max_steps
// ---------------------------------------------------------------------------

it('task is marked FAILED when step_count reaches max_steps', function (): void {
    [$agentId] = seedAgent();

    $callNum = 0;
    $mock    = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callNum) {
        $callNum++;

        return new LLMResponse(null, [new DriverToolCall("call_{$callNum}", 'stub_input', [])], 5, 3, "cmp_{$callNum}");
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Infinite loop', maxSteps: 3);

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->failure_reason)->toBe('Max steps reached.')
        ->and($task->step_count)->toBe(3);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Fix #5 — tool exception recovery
// ---------------------------------------------------------------------------

it('tool exception is caught, encoded as an error ToolResult, and the loop survives', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_throw', 'throwing_tool', [])], 5, 3, 'cmp_1')
            : new LLMResponse('Recovered after plugin error.', [], 5, 3, 'cmp_2');
    });

    $tools = [new ThrowingTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Use failing tool', maxSteps: 10);

    $task->refresh();
    // Task must NOT be a zombie — the loop completed despite the exception.
    expect($task->status)->toBe('COMPLETED');

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->result_content)->toContain('System Error');
    expect($toolCallRecord->result_content)->toContain('Community plugin exploded!');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume()
// ---------------------------------------------------------------------------

it('resume executes the approved OutputTool, appends history, and re-dispatches tick', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_r', 'stub_output', ['x' => 1])], 5, 3, 'cmp_1')
            : new LLMResponse('Resumed.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Resume test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // New batch format: list<{provider_call_id, arguments}>.
    $orch->resume($task->id, [['provider_call_id' => 'call_r', 'arguments' => ['x' => 99]]]);

    $task->refresh();
    // 2 LLM turns: tick 1 (tool call paused) + tick 2 (after resume).
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2)
        ->and($task->final_response)->toBe('Resumed.');

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->approved_arguments)->toBe(['x' => 99]);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('keeps the task status as PENDING_APPROVAL during tool execution to prevent async daemon race conditions', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_race', 'race_condition_checker_tool', [])], 5, 3, 'cmp_1')
            : new LLMResponse('Finished.', [], 5, 3, 'cmp_2');
    });

    // Create an inline diagnostic tool that checks the Task's status directly from the DB mid-execution
    $checkerTool = new
    #[Tool(name: 'race_condition_checker_tool', description: 'Checks task status')]
    #[ToolOperation(name: 'default', description: 'Run check', enabledByDefault: true, requiresApprovalByDefault: true)]
    class implements ToolInterface {
        use HasOperations;
        public ?string $statusInsideTool = null;
        public ?int $taskId = null;
        public function getParametersSchema(): array
        {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }
        public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
        {
            // Task status should still be PENDING_APPROVAL while the tool is heavily executing
            $task = Task::find($this->taskId);
            $this->statusInsideTool = $task->status;
            return new ToolResult(true, 'Checked.');
        }
        public function describeAction(array $arguments): string
        {
            return 'Checking.';
        }
    };

    $tools = [$checkerTool];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);

    // Start task, it pauses
    $task = $orch->start($agentId, 'Test race condition', maxSteps: 10);
    $checkerTool->taskId = $task->id;

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    $orch->resume($task->id, [['provider_call_id' => 'call_race', 'arguments' => []]]);

    expect($checkerTool->statusInsideTool)->toBe('PENDING_APPROVAL', 'The task status should NOT flip to RUNNING before the tool finishes executing.');

    $task->refresh();
    expect($task->status)->toBe('COMPLETED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('resume throws when task is not PENDING_APPROVAL', function (): void {
    [$agentId] = seedAgent();

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'x',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $mock = Mockery::mock(LLMDriverInterface::class);
    $orch = makeOrchestrator(mockDriverFactory($mock));

    expect(fn() => $orch->resume($task->id, []))->toThrow(InvalidTaskTransitionException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Fix #4 — schema validation in resume()
// ---------------------------------------------------------------------------

it('resume throws InvalidArgumentException when approved arguments fail schema validation', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_schema', 'stub_output_with_schema', ['recipient' => 'a@b.com'])], 5, 3, 'cmp_1')
            : new LLMResponse('Oh sorry let me fix that.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputToolWithSchema()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Schema validation test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // Human forgot the required 'recipient' field — schema validation must catch this gracefully now.
    $orch->resume($task->id, [['provider_call_id' => 'call_schema', 'arguments' => []]]);

    $toolCall = ToolCallModel::where('provider_call_id', 'call_schema')->first();
    expect($toolCall->status)->toBe('APPROVED')
        ->and($toolCall->result_content)->toContain(VALIDATION_ERROR);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// reject()
// ---------------------------------------------------------------------------

it('reject injects rejection into history and re-dispatches tick', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_rej', 'stub_output', [])], 5, 3, 'cmp_1')
            : new LLMResponse('Ok, rejected.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Reject test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    $orch->reject($task->id, 'Too risky');

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Ok, rejected.');

    $allHistory = TaskHistory::where('task_id', $task->id)->where('role', 'tool')->get();
    $rejectionContent = $allHistory->first(fn($r) => str_contains((string) $r->content, 'Too risky'));
    expect($rejectionContent)->not()->toBeNull();

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('REJECTED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('reject throws when task is not PENDING_APPROVAL', function (): void {
    [$agentId] = seedAgent();

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'COMPLETED',
        'user_prompt' => 'x',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $mock = Mockery::mock(LLMDriverInterface::class);
    $orch = makeOrchestrator(mockDriverFactory($mock));

    expect(fn() => $orch->reject($task->id, 'reason'))->toThrow(InvalidTaskTransitionException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Dependency Injection / Context Verification
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

it('logs a debug entry with tool name, agent_id, task_id, and arguments on every dispatch', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_log', 'stub_input', ['x' => 42])], 5, 3, 'cmp_1')
            : new LLMResponse('Done.', [], 5, 3, 'cmp_2');
    });

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('debug')
        ->once()
        ->withArgs(static function (string $msg, array $ctx): bool {
            return $msg === 'Tool dispatch'
                && $ctx['tool'] === 'stub_input'
                && isset($ctx['agent_id'])
                && isset($ctx['task_id'])
                && $ctx['arguments'] === ['x' => 42];
        });
    $logger->allows('error');  // allow but don't require error calls on the success path

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools, $logger);
    $orch->start($agentId, 'Log dispatch test', maxSteps: 10);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('logs an error when a tool returns a failed ToolResult, without including arguments', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_fail', 'stub_failing', ['secret' => 'password123'])], 5, 3, 'cmp_1')
            : new LLMResponse('Handled.', [], 5, 3, 'cmp_2');
    });

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class);
    $logger->allows('debug');
    $logger->shouldReceive('error')
        ->once()
        ->withArgs(static function (string $msg, array $ctx): bool {
            return $msg === 'Tool returned failure'
                && $ctx['tool'] === 'stub_failing'
                && isset($ctx['agent_id'])
                && isset($ctx['task_id'])
                && str_contains((string) $ctx['content'], 'Stub tool failure')
                // Arguments must NOT be present in error logs (PII protection).
                && !isset($ctx['arguments']);
        });

    $tools = [new StubFailingTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools, $logger);
    $orch->start($agentId, 'Log failure test', maxSteps: 10);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('logs an error with exception_class when a tool throws, without including arguments', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_throw', 'throwing_tool', ['private' => 'data'])], 5, 3, 'cmp_1')
            : new LLMResponse('Recovered.', [], 5, 3, 'cmp_2');
    });

    $logger = Mockery::mock(Psr\Log\LoggerInterface::class);
    $logger->allows('debug');
    $logger->shouldReceive('error')
        ->once()
        ->withArgs(static function (string $msg, array $ctx): bool {
            return $msg === 'Tool threw exception'
                && $ctx['tool'] === 'throwing_tool'
                && isset($ctx['exception_class'])
                && str_contains((string) $ctx['message'], 'Community plugin exploded!')
                // Arguments must NOT be present in error logs (PII protection).
                && !isset($ctx['arguments']);
        });

    $tools = [new ThrowingTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools, $logger);
    $orch->start($agentId, 'Log exception test', maxSteps: 10);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Dependency Injection / Context Verification
// ---------------------------------------------------------------------------

it('injects the correct agentId into the tool execute scope', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_spy', 'spy_agent_input', [])], 10, 5, 'cmp_1');
        }
        return new LLMResponse('Done.', [], 10, 5, 'cmp_2');
    });

    $tools = [new SpyAgentIdInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Verify agent context', maxSteps: 5);

    $task->refresh();

    // The tool should have returned "Agent ID is: {$agentId}"
    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->result_content)->toBe("Agent ID is: {$agentId}");
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Fix: handleToolCalls schema-validation failure writes ToolCall + history atomically
// ---------------------------------------------------------------------------

it('handleToolCalls schema validation failure writes both ToolCall and history row', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        // First turn: LLM calls stub_output_with_schema without the required 'recipient' field
        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_schema_fail', 'stub_output_with_schema', [])], 5, 3, 'cmp_1')
            : new LLMResponse('Recovered.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputToolWithSchema()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Schema fail test', maxSteps: 10);

    $task->refresh();
    // The error must be fed back to the LLM so the task can complete, not get stuck
    expect($task->status)->toBe('COMPLETED');

    // ToolCall record must be APPROVED with the validation error message
    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord)->not()->toBeNull()
        ->and($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->result_content)->toContain(VALIDATION_ERROR);

    // A history row for this tool call must also exist (atomically written with the record above)
    $toolHistory = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_schema_fail')
        ->first();
    expect($toolHistory)->not()->toBeNull()
        ->and($toolHistory->content)->toContain(VALIDATION_ERROR);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('handleToolCalls schema validation failure does not pause for approval', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_schema_fail2', 'stub_output_with_schema', [])], 5, 3, 'cmp_1')
            : new LLMResponse('Done.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputToolWithSchema()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Schema fail no approval', maxSteps: 10);

    $task->refresh();
    // Task must NOT be stuck in PENDING_APPROVAL — validation failure skips the approval gate
    expect($task->status)->not()->toBe('PENDING_APPROVAL')
        ->and($task->pending_state)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Empty arguments normalization (MiniMax/LM Studio compatibility)
// ---------------------------------------------------------------------------

it('continues correctly when LLM sends tool call with empty array arguments "[]"', function (): void {
    [$agentId] = seedAgent();

    // Simulate the first LLM response where MiniMax sent "arguments":"[]" (string)
    // The tool has no parameters, so empty args are valid.
    // This is stored in tool_call_payload as the string "[]".
    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Get current time',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);

    // Seed the conversation: user prompt + assistant tool call with "[]" (empty array as string)
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Get current time']);
    TaskHistory::create([
        'task_id'           => $task->id,
        'sequence'          => 1,
        'role'              => 'assistant',
        'content'           => null,
        'tool_call_payload' => json_encode([
            ['id' => 'call_empty', 'type' => 'function', 'function' => ['name' => 'stub_input', 'arguments' => '[]']],
        ]),
    ]);
    // Tool result with empty content
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 2,
        'role'         => 'tool',
        'content'      => 'Current Date & Time: 2026-04-13T15:29:43+00:00',
        'tool_call_id' => 'call_empty',
        'tool_name'    => 'stub_input',
    ]);

    // The LLM should receive "arguments": {} (empty object), NOT "arguments": "[]" (string).
    // MiniMax/LM Studio reject "[]" when the schema declares type "object".
    $receivedArgs = null;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function ($request) use (&$receivedArgs) {
        // Capture the arguments from the continuation request
        foreach ($request->messages as $msg) {
            if (isset($msg['tool_calls'])) {
                foreach ($msg['tool_calls'] as $tc) {
                    $receivedArgs = $tc['function']['arguments'];
                }
            }
        }
        return new LLMResponse('Done.', [], 10, 5, 'cmp_2');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);

    $orch->tick($task->id);

    // The task should complete without error
    $task->refresh();
    expect($task->status)->toBe('COMPLETED');

    // The arguments sent to the LLM should be "{}" (object), NOT "[]" (array/string)
    expect($receivedArgs)->not()->toBe('[]')
        ->and($receivedArgs)->toBe('{}');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('buildMessages normalizes empty array arguments "[]" to empty object "{}" before sending to LLM', function (): void {
    // This is a unit test for buildMessages specifically
    [$agentId] = seedAgent();

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    // Insert history with "arguments":"[]" (string form, as MiniMax sends)
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hi']);
    TaskHistory::create([
        'task_id'           => $task->id,
        'sequence'          => 1,
        'role'              => 'assistant',
        'tool_call_payload' => json_encode([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'stub_input', 'arguments' => '[]']],
        ]),
    ]);

    // Capture what buildMessages produces
    /** @var list<array<string,mixed>> $capturedMessages */
    $capturedMessages = [];

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function ($request) use (&$capturedMessages) {
        $capturedMessages = $request->messages;
        return new LLMResponse('ok', [], 5, 3, 'cmp_1');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);

    $orch->tick($task->id);

    // Find the tool call message
    $toolCallMsg = null;
    foreach ($capturedMessages as $msg) {
        if (isset($msg['tool_calls'])) {
            $toolCallMsg = $msg['tool_calls'][0];
            break;
        }
    }

    expect($toolCallMsg['function']['arguments'])->toBe('{}');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('buildMessages skips rows covered by a summary and includes the summary row itself', function (): void {
    [$agentId] = seedAgent();

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    // Insert history: 3 user messages (sequences 0, 1, 2) then a summary row
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'Hi there']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 2, 'role' => 'user', 'content' => 'What is the time?']);
    // Summary covering sequences 0-2
    TaskHistory::create([
        'task_id' => $task->id,
        'sequence' => 3,
        'role' => 'summary',
        'content' => 'User asked about time. Assistant responded.',
        'summarized_sequence_range' => '0-2',
    ]);
    // Recent history after the summary
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 4, 'role' => 'user', 'content' => 'Thanks']);

    // Capture what buildMessages produces
    /** @var list<array<string,mixed>> $capturedMessages */
    $capturedMessages = [];

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function ($request) use (&$capturedMessages) {
        $capturedMessages = $request->messages;
        return new LLMResponse('Done', [], 5, 3, 'cmp_1');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);

    $orch->tick($task->id);

    // Should have exactly 2 messages: the summary row and the "Thanks" user message
    // The original 3 rows (sequences 0-2) should be skipped
    expect(count($capturedMessages))->toBe(2);

    expect($capturedMessages[0]['role'])->toBe('summary');
    expect($capturedMessages[0]['content'])->toBe('User asked about time. Assistant responded.');

    expect($capturedMessages[1]['role'])->toBe('user');
    expect($capturedMessages[1]['content'])->toBe('Thanks');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('buildMessages skips multiple summary ranges and only includes post-summary rows', function (): void {
    [$agentId] = seedAgent();

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    // First conversation block: user message -> summary covering only that message
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'First']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'summary', 'content' => 'First summary', 'summarized_sequence_range' => '0-0']);
    // Second conversation block: user message -> summary covering only that message
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 2, 'role' => 'user', 'content' => 'Second']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 3, 'role' => 'summary', 'content' => 'Second summary', 'summarized_sequence_range' => '2-2']);
    // Recent history
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 4, 'role' => 'user', 'content' => 'Recent']);

    /** @var list<array<string,mixed>> $capturedMessages */
    $capturedMessages = [];

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function ($request) use (&$capturedMessages) {
        $capturedMessages = $request->messages;
        return new LLMResponse('Done', [], 5, 3, 'cmp_1');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);

    $orch->tick($task->id);

    // buildMessages iterates sequentially: when summary-2 (seq 3, range 2-2) is encountered,
    // it removes messages with sequence <= 2. summary-1 (seq 1) is NOT in range 2-2, so it is preserved.
    // Result: First summary + Second summary + Recent = 3 messages.
    expect(count($capturedMessages))->toBe(3);
    expect($capturedMessages[0]['role'])->toBe('summary');
    expect($capturedMessages[0]['content'])->toBe('First summary');
    expect($capturedMessages[1]['role'])->toBe('summary');
    expect($capturedMessages[1]['content'])->toBe('Second summary');
    expect($capturedMessages[2]['role'])->toBe('user');
    expect($capturedMessages[2]['content'])->toBe('Recent');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resolveLlmConfig() — config resolution chain
// ---------------------------------------------------------------------------

test('resolveLlmConfig throws when no config exists at any level', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('non-config@example.com', TEST_PASSWORD, 'Nonconfig');

    // Create agent WITHOUT any config AND without a global default existing
    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Agent Without Config',
        'llm_driver_config_id' => null,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    expect(fn() => $orch->start($agent->id, 'Hello', maxSteps: 5))
        ->toThrow(RuntimeException::class, 'No LLM configuration set for this agent');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('resolveLlmConfig uses user preference when agent has no llm_driver_config_id', function (): void {
    [$agentId, $userId] = seedAgent();

    // Create a config and set it as user preference
    $config = LLMDriverConfiguration::create([
        'user_id' => $userId,
        'name' => USER_PREFERRED_CONFIG_NAME,
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-test', 'model' => 'gpt-4o']),
        'is_global' => false,
    ]);
    $config->context_window = 64000;
    $config->max_tokens_output = 2048;
    $config->save();

    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    // Should not throw - uses user preference
    $task = $orch->start($agentId, 'Hello', maxSteps: 5);
    expect($task->status)->toBe('COMPLETED');

    // Cleanup
    UserPreference::where('user_id', $userId)->delete();
    LLMDriverConfiguration::where('id', $config->id)->delete();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('resolveLlmConfig prefers user preference over global default', function (): void {
    [$agentId, $userId] = seedAgent();

    // Create a global default config
    $globalConfig = LLMDriverConfiguration::create([
        'user_id' => null,
        'name' => 'Global Default Config',
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-global', 'model' => 'gpt-4o']),
        'is_global' => true,
        'is_default' => true,
    ]);
    $globalConfig->context_window = 32000;
    $globalConfig->max_tokens_output = 1024;
    $globalConfig->save();

    // Create a user preference config
    $prefConfig = LLMDriverConfiguration::create([
        'user_id' => $userId,
        'name' => USER_PREFERRED_CONFIG_NAME,
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-pref', 'model' => 'gpt-4o']),
        'is_global' => false,
    ]);
    $prefConfig->context_window = 64000;
    $prefConfig->max_tokens_output = 2048;
    $prefConfig->save();

    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $prefConfig->id,
    ]);

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    // Should use user preference, not global default
    $task = $orch->start($agentId, 'Hello', maxSteps: 5);
    expect($task->status)->toBe('COMPLETED');

    // Cleanup
    UserPreference::where('user_id', $userId)->delete();
    LLMDriverConfiguration::whereIn('id', [$globalConfig->id, $prefConfig->id])->delete();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('resolveLlmConfig uses agent-specific config when set', function (): void {
    [$agentId, $userId] = seedAgent();

    // Create user preference config
    $prefConfig = LLMDriverConfiguration::create([
        'user_id' => $userId,
        'name' => USER_PREFERRED_CONFIG_NAME,
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-pref', 'model' => 'gpt-4o']),
        'is_global' => false,
    ]);
    $prefConfig->context_window = 64000;
    $prefConfig->max_tokens_output = 2048;
    $prefConfig->save();

    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $prefConfig->id,
    ]);

    // Create agent-specific config
    $agentConfig = LLMDriverConfiguration::create([
        'user_id' => $userId,
        'name' => 'Agent Config',
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-agent', 'model' => 'gpt-4o']),
        'is_global' => false,
    ]);
    $agentConfig->context_window = 128000;
    $agentConfig->max_tokens_output = 4096;
    $agentConfig->save();

    // Set the agent to use the agent-specific config
    $agent = Agent::find($agentId);
    $agent->llm_driver_config_id = $agentConfig->id;
    $agent->save();

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    // Should use agent-specific config, not user preference
    $task = $orch->start($agentId, 'Hello', maxSteps: 5);
    expect($task->status)->toBe('COMPLETED');

    // Cleanup
    UserPreference::where('user_id', $userId)->delete();
    LLMDriverConfiguration::whereIn('id', [$prefConfig->id, $agentConfig->id])->delete();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('resolveLlmConfig uses agent user_id to find preference - user isolation', function (): void {
    // This test documents intentional behavior: resolveLlmConfig uses agent->user_id
    // to find the user's preference. In async runner context, the agent carries the
    // user context. Each user only sees their own preference, not another user's.
    $authService = bootAuthLayer();

    $userA = $authService->register('user-a-iso@example.com', TEST_PASSWORD, 'UseraIso');
    $userB = $authService->register('user-b-iso@example.com', TEST_PASSWORD, 'UserbIso');

    // User A creates their own config
    $configA = LLMDriverConfiguration::create([
        'user_id' => $userA,
        'name' => 'User A Config',
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-usera', 'model' => 'gpt-4o']),
        'is_global' => false,
        'context_window' => 64000,
        'max_tokens_output' => 2048,
    ]);

    // User A sets preference for their own config
    UserPreference::create([
        'user_id' => $userA,
        'preferred_llm_config_id' => $configA->id,
    ]);

    // User B creates their own config
    $configB = LLMDriverConfiguration::create([
        'user_id' => $userB,
        'name' => 'User B Config',
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-userb', 'model' => 'gpt-4o']),
        'is_global' => false,
        'context_window' => 32000,
        'max_tokens_output' => 1024,
    ]);

    // User B sets preference for their own config
    UserPreference::create([
        'user_id' => $userB,
        'preferred_llm_config_id' => $configB->id,
    ]);

    // Create agents for both users
    $agentA = Agent::create([
        'user_id' => $userA,
        'name' => 'User A Agent',
        'llm_driver_config_id' => null,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $agentB = Agent::create([
        'user_id' => $userB,
        'name' => 'User B Agent',
        'llm_driver_config_id' => null,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    // User A's agent should get User A's config (via user_id = A in preference lookup)
    $llmA = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orchA = makeOrchestrator(mockDriverFactory($llmA));
    $taskA = $orchA->start($agentA->id, 'Hello', maxSteps: 5);
    expect($taskA->status)->toBe('COMPLETED');

    // User B's agent should get User B's config (via user_id = B in preference lookup)
    $llmB = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_2'));
    $orchB = makeOrchestrator(mockDriverFactory($llmB));
    $taskB = $orchB->start($agentB->id, 'Hello', maxSteps: 5);
    expect($taskB->status)->toBe('COMPLETED');

    // Cleanup
    UserPreference::whereIn('user_id', [$userA, $userB])->delete();
    LLMDriverConfiguration::whereIn('id', [$configA->id, $configB->id])->delete();
    Agent::whereIn('id', [$agentA->id, $agentB->id])->delete();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// buildToolDefinitions — memory tool resolution
// ---------------------------------------------------------------------------

test('LLM can call both memory and global_memory tools in same session', function (): void {
    [$agentId] = seedAgent();

    $memoryTool = new AgentMemoryTool();
    $globalMemoryTool = new GlobalMemoryTool();
    enableToolsForAgent($agentId, [$memoryTool, $globalMemoryTool]);

    $callLog = [];
    $callCount = 0;

    // First LLM call: returns tool calls for both memory tools
    // Second LLM call: returns a final text response (after tools are auto-approved)
    $llm = Mockery::mock(LLMDriverInterface::class);
    $llm->allows('complete')->andReturnUsing(static function () use (&$callLog, &$callCount) {
        $callLog[] = 'complete';
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(
                content: '',
                toolCalls: [
                    new DriverToolCall('call_1', 'memory', ['action' => 'list']),
                    new DriverToolCall('call_2', 'global_memory', ['action' => 'list']),
                ],
                inputTokens: 10,
                outputTokens: 5,
                completionId: 'cmp_1',
            );
        }
        return new LLMResponse('Done listing memories.', [], 10, 5, 'cmp_2');
    });
    $llm->allows('getProviderName')->andReturn('mock');
    $llm->allows('getModelName')->andReturn('mock-model');

    $orch = makeOrchestrator(mockDriverFactory($llm), [$memoryTool, $globalMemoryTool]);

    $task = $orch->start($agentId, 'What memories do I have?', maxSteps: 5);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2);

    $toolCalls = ToolCallModel::where('task_id', $task->id)->get();
    expect($toolCalls)->toHaveCount(2);

    $toolNames = $toolCalls->pluck('tool_name')->toArray();
    expect($toolNames)->toContain('memory')
        ->and($toolNames)->toContain('global_memory');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// publishIntermediateState — Mercure duplicate fix
// ---------------------------------------------------------------------------

it('publishes intermediate state exactly once when tools are auto-approved', function (): void {
    [$agentId] = seedAgent();

    $publishCount = 0;
    $mockMercure = Mockery::mock(MercurePublisherInterface::class);
    $mockMercure->allows('publish')
        ->andReturnUsing(static function () use (&$publishCount): bool {
            $publishCount++;

            return true;
        });

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_1', 'stub_input', [])], 10, 5, 'cmp_1');
        }
        return new LLMResponse('Done.', [], 10, 5, 'cmp_2');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);

    $orch = new Orchestrator(
        driverFactory: mockDriverFactory($mock),
        toolInstances: $tools,
        mercure: $mockMercure,
    );

    $task = $orch->start($agentId, 'Auto approve test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2);

    // When all tools are auto-approved, publishIntermediateState should be called exactly once:
    // - Line 787 publishes before the recursive tick
    // - Line 821 should NOT publish again (that was the bug)
    expect($publishCount)->toBe(1);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('publishes intermediate state when tools require approval', function (): void {
    [$agentId] = seedAgent();

    $publishCount = 0;
    $mockMercure = Mockery::mock(MercurePublisherInterface::class);
    $mockMercure->allows('publish')
        ->andReturnUsing(static function () use (&$publishCount): bool {
            $publishCount++;

            return true;
        });

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(
        new LLMResponse(null, [new DriverToolCall('call_out', 'stub_output', ['key' => 'val'])], 10, 5, 'cmp_1'),
    );

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);

    $orch = new Orchestrator(
        driverFactory: mockDriverFactory($mock),
        toolInstances: $tools,
        mercure: $mockMercure,
    );

    $task = $orch->start($agentId, 'Approval test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // When approval is needed, publishIntermediateState is called exactly once (line 821)
    expect($publishCount)->toBe(1);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// NO_LLM_CONFIGURATION error handling — task state persistence
// ---------------------------------------------------------------------------

test('tick sets NO_LLM_CONFIGURATION error code and message when resolveLlmConfig throws', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('no-config@example.com', TEST_PASSWORD, 'Noconfig');

    // Agent with no LLM config and no global default — resolveLlmConfig() will throw.
    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Agent Without Config',
        'llm_driver_config_id' => null,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    // start() creates a RUNNING task then calls tick() which throws inside the transaction.
    try {
        $orch->start($agent->id, 'Hello', maxSteps: 5);
        PHPUnit\Framework\Assert::fail('Expected RuntimeException was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('No LLM configuration set for this agent. Set a preferred config or ensure a global default exists.');
    }

    // Refresh task from DB — the outer catch in tick() marks it FAILED.
    $task = Task::where('agent_id', $agent->id)->first();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('NO_LLM_CONFIGURATION')
        ->and($task->error_message)->toBe('No LLM configuration set. Please configure an LLM driver or set a global default.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// continue()
// ---------------------------------------------------------------------------

it('continue() updates Task.user_prompt to the new prompt', function (): void {
    [$agentId] = seedAgent();

    $llm = mockLlm(new LLMResponse('Continued response.', [], 10, 5, 'cmp_cont'));
    $orch = makeOrchestrator(mockDriverFactory($llm), [], null, WorkerMode::Sync);

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'COMPLETED',
        'user_prompt' => PROMPT_ORIGINAL,
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);

    TaskHistory::create([
        'task_id'  => $task->id,
        'role'     => 'user',
        'content'  => PROMPT_ORIGINAL,
        'sequence' => 0,
    ]);

    $continuedTask = $orch->continue($task->id, PROMPT_CONTINUED);

    // user_prompt MUST be updated to the new prompt (the bug this tests)
    expect($continuedTask->user_prompt)->toBe(PROMPT_CONTINUED);

    // History should contain the new continuation prompt as the last user message
    $userEntries = TaskHistory::where('task_id', $task->id)
        ->where('role', 'user')
        ->orderBy('id')
        ->get();

    expect($userEntries->count())->toBe(2)
        ->and($userEntries->first()->content)->toBe(PROMPT_ORIGINAL)
        ->and($userEntries->last()->content)->toBe(PROMPT_CONTINUED);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('buildToolDefinitions only queries operation overrides for enabled tool classes', function (): void {
    [$agentId, $userId] = seedAgent();

    // Enable query log to verify the whereIn clause is used
    Illuminate\Database\Capsule\Manager::connection()->enableQueryLog();
    Illuminate\Database\Capsule\Manager::connection()->flushQueryLog();

    $orch = makeOrchestrator(
        Mockery::mock(DriverFactory::class),
        [new StubInputTool(), new StubOutputTool()],
    );

    $reflection = new ReflectionClass($orch);
    $method = $reflection->getMethod('buildToolDefinitions');

    // Call it with only StubInputTool enabled
    $method->invoke($orch, [StubInputTool::class], $agentId, $userId);

    $logs = Illuminate\Database\Capsule\Manager::connection()->getQueryLog();
    Illuminate\Database\Capsule\Manager::connection()->disableQueryLog();

    // Find the override query in the log
    $overrideQueryLog = array_filter($logs, fn($log) => str_contains($log['query'], 'agent_tool_operation_overrides'));

    expect($overrideQueryLog)->not->toBeEmpty();
    $query = reset($overrideQueryLog)['query'];

    // Check that it includes the 'in' clause for the tool_class
    expect($query)->toContain('in (?)');
});

// ---------------------------------------------------------------------------
// continue() — error cases and additionalSteps
// ---------------------------------------------------------------------------

it('continue() throws RuntimeException when task status is not COMPLETED or FAILED', function (): void {
    [$agentId] = seedAgent();

    $llm  = mockLlm(new LLMResponse('Done.', [], 5, 3, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'still running',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    expect(fn() => $orch->continue($task->id, 'new prompt'))
        ->toThrow(RuntimeException::class, 'Can only continue completed or failed tasks.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('continue() overrides max_steps when additionalSteps is supplied', function (): void {
    [$agentId] = seedAgent();

    $llm  = mockLlm(new LLMResponse('Continued.', [], 5, 3, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'COMPLETED',
        'user_prompt' => PROMPT_ORIGINAL,
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    $continuedTask = $orch->continue($task->id, PROMPT_CONTINUED, additionalSteps: 25);

    // additionalSteps overrides the previous max_steps
    expect($continuedTask->max_steps)->toBe(25)
        // tick() ran once and incremented step_count from 0 → 1
        ->and($continuedTask->step_count)->toBe(1)
        ->and($continuedTask->user_prompt)->toBe(PROMPT_CONTINUED);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — error path: non-context-window LLMProviderException
// ---------------------------------------------------------------------------

it('tick marks task FAILED with error_code, error_message, and failure_reason when LLM throws non-context-window error', function (): void {
    [$agentId] = seedAgent();

    // 401 unauthorized — classifyError returns AUTH_ERROR
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andThrow(new LLMProviderException('Provider API error 401: unauthorized'));

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Hello',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);

    try {
        $orch->tick($task->id);
        PHPUnit\Framework\Assert::fail('Expected LLMProviderException to propagate');
    } catch (LLMProviderException $e) {
        // Expected
    }

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('AUTH_ERROR')
        ->and($task->error_message)->toBe('API authentication failed. Please check your API key.')
        ->and($task->failure_reason)->toBe('Provider API error 401: unauthorized');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('tick classifies RateLimitException as RATE_LIMIT and marks task FAILED', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andThrow(new LLMRateLimitException('OpenAI rate limit hit'));

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Hello',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);

    try {
        $orch->tick($task->id);
        PHPUnit\Framework\Assert::fail('Expected LLMRateLimitException to propagate');
    } catch (LLMRateLimitException $e) {
        // Expected
    }

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('RATE_LIMIT')
        ->and($task->error_message)->toBe('The AI service is busy. Try again in a moment.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('tick classifies RetryableException with 529 as SERVER_OVERLOADED', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andThrow(new LLMRetryableException('Provider error 529: overloaded'));

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Hello',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);

    try {
        $orch->tick($task->id);
        PHPUnit\Framework\Assert::fail('Expected LLMRetryableException to propagate');
    } catch (LLMRetryableException $e) {
        // Expected
    }

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('SERVER_OVERLOADED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — context window error: first turn (historyCount <= 1)
// ---------------------------------------------------------------------------

it('tick marks task FAILED with CONTEXT_WINDOW_FIRST_TURN on first-turn context window error', function (): void {
    [$agentId] = seedAgent();

    // LLM throws an LLMProviderException whose message contains a JSON body that the
    // ContextWindowErrorParser recognizes as a context-window error.
    $errorJson = json_encode(['error' => ['type' => 'context_window_exceeded', 'message' => 'Context window exceeds limit (2013)']]);
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andThrow(new LLMProviderException("Provider API error 400: {$errorJson}"));

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Hello',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    // Only the user history row — count = 1 (the only row).
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);

    try {
        $orch->tick($task->id);
        PHPUnit\Framework\Assert::fail('Expected LLMProviderException to propagate after CONTEXT_WINDOW_FIRST_TURN');
    } catch (LLMProviderException $e) {
        // Expected
    }

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('CONTEXT_WINDOW_FIRST_TURN')
        ->and($task->error_message)->toContain('Context window too small')
        ->and($task->error_message)->toContain('2013');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — context window error: compaction + retry succeeds
// ---------------------------------------------------------------------------

it('tick compacts history and retries successfully when context window error happens on a long conversation', function (): void {
    [$agentId] = seedAgent();

    $errorJson = json_encode(['error' => ['type' => 'context_window_exceeded', 'message' => 'Context window exceeds limit (8192)']]);

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function (LLMRequest $request) use (&$callCount, $errorJson) {
        $callCount++;
        // First call: LLM throws a context window error.
        if ($callCount === 1) {
            throw new LLMProviderException("Provider API error 400: {$errorJson}");
        }
        // Second call: summarization request — return a summary.
        if ($callCount === 2) {
            return new LLMResponse('Summary of past conversation.', [], 5, 3, 'cmp_summary');
        }
        // Third call: retried tick — return a final text response.
        return new LLMResponse('Recovered after compaction.', [], 5, 3, 'cmp_done');
    });

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Original',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    // Need more than 5 history rows so compactHistory has work to do.
    for ($i = 0; $i < 8; $i++) {
        TaskHistory::create([
            'task_id'  => $task->id,
            'sequence' => $i,
            'role'     => $i % 2 === 0 ? 'user' : 'assistant',
            'content'  => "Message {$i}",
        ]);
    }

    $orch->tick($task->id);

    // LLM was called 3 times: initial, summarization, retry.
    expect($callCount)->toBe(3);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Recovered after compaction.');

    // The summary row should exist.
    $summaryRows = TaskHistory::where('task_id', $task->id)->where('role', 'summary')->get();
    expect($summaryRows)->not->toBeEmpty();
    expect($summaryRows->first()->content)->toBe('Summary of past conversation.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// handleToolCalls — disabled operation path
// ---------------------------------------------------------------------------

it('handleToolCalls writes a DISABLED ToolCall record when operation is disabled for the agent', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_disabled', 'stub_input', ['action' => 'default'])], 5, 3, 'cmp_1');
        }

        return new LLMResponse('Recovered.', [], 5, 3, 'cmp_2');
    });

    // Disable the 'default' operation of StubInputTool for this agent.
    AgentToolOperationOverride::create([
        'agent_id'                  => $agentId,
        'tool_class'                => StubInputTool::class,
        'operation'                 => 'default',
        'enabled'                   => 0,
        'default_requires_approval' => null,
    ]);

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Disabled op test', maxSteps: 10);

    $task->refresh();
    // After the LLM tool call is disabled, no tool call is pending approval,
    // so the orchestrator recurses to tick() and the LLM is called a second time.
    expect($task->status)->toBe('COMPLETED');

    $toolCall = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCall)->not->toBeNull()
        ->and($toolCall->status)->toBe('DISABLED')
        ->and($toolCall->tool_type)->toBe('operation');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// handleToolCalls — tool not enabled for the agent
// ---------------------------------------------------------------------------

it('handleToolCalls writes a System Error ToolCall when LLM calls a tool that is not enabled for the agent', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    // First call: LLM invokes a tool the agent has not enabled.
    // Second call: LLM returns a final text response.
    $callCount = 0;
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_unauth', 'stub_input', [])], 5, 3, 'cmp_1');
        }

        return new LLMResponse('Recovered.', [], 5, 3, 'cmp_2');
    });

    // The agent is NOT given the StubInputTool — enableToolsForAgent is intentionally omitted.
    $orch = makeOrchestrator(mockDriverFactory($mock), [new StubInputTool()]);
    $task = $orch->start($agentId, 'Tool not enabled test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED');

    // The error message should be in the tool history (as a 'tool' role row).
    $errorHistory = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_unauth')
        ->first();
    expect($errorHistory)->not->toBeNull()
        ->and($errorHistory->content)->toContain('System Error')
        ->and($errorHistory->content)->toContain('not enabled');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume() — dangling PENDING_APPROVAL records
// ---------------------------------------------------------------------------

it('resume cleans up stranded PENDING_APPROVAL records not covered by the approved batch', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        // First LLM call: two parallel tool calls.
        if ($callCount === 1) {
            return new LLMResponse(null, [
                new DriverToolCall('call_a', 'stub_output', ['x' => 1]),
                new DriverToolCall('call_b', 'stub_output', ['x' => 2]),
            ], 5, 3, 'cmp_1');
        }
        // Second LLM call: a final text response after resume.
        return new LLMResponse('Done after dangling cleanup.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Dangling PENDING test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // Both ToolCall records are PENDING_APPROVAL.
    $pendingCount = ToolCallModel::where('task_id', $task->id)->where('status', 'PENDING_APPROVAL')->count();
    expect($pendingCount)->toBe(2);

    // Corrupt the saved state to only contain call_a. This simulates a state mismatch
    // (e.g. a tool call that was queued for approval but not in the saved snapshot).
    $state = AgentState::fromJson((string) $task->pending_state);
    $stateArray = json_decode($state->toJson(), true);
    $stateArray['pending_tool_calls'] = [
        [
            'provider_call_id' => 'call_a',
            'tool_name'        => 'stub_output',
            'arguments'        => ['x' => 1],
        ],
    ];
    Task::where('id', $task->id)->update(['pending_state' => json_encode($stateArray)]);

    // Approve only call_a; call_b is dangling and must be rejected by cleanup.
    $orch->resume($task->id, [['provider_call_id' => 'call_a', 'arguments' => ['x' => 99]]]);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED');

    $approved = ToolCallModel::where('task_id', $task->id)->where('status', 'APPROVED')->get();
    $rejected = ToolCallModel::where('task_id', $task->id)->where('status', 'REJECTED')->get();
    expect($approved)->toHaveCount(1)
        ->and($approved->first()->provider_call_id)->toBe('call_a')
        ->and($rejected)->toHaveCount(1)
        ->and($rejected->first()->provider_call_id)->toBe('call_b');

    // The dangling PENDING_APPROVAL should have a history row explaining the discard.
    $discarded = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_b')
        ->first();
    expect($discarded)->not->toBeNull()
        ->and($discarded->content)->toContain('discarded');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume() — exception path: task is marked RESUME_FAILED
// ---------------------------------------------------------------------------

it('resume marks task RESUME_FAILED and re-throws when the recursive tick fails', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        // First LLM call: pause for approval.
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_x', 'stub_output', [])], 5, 3, 'cmp_1');
        }
        // Second LLM call (recursive tick after resume): blow up.
        throw new RuntimeException('LLM is down on the resumed tick.');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Resume exception test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    try {
        $orch->resume($task->id, [['provider_call_id' => 'call_x', 'arguments' => []]]);
        PHPUnit\Framework\Assert::fail('Expected RuntimeException to propagate from recursive tick');
    } catch (RuntimeException $e) {
        // Expected
    }

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('RESUME_FAILED')
        ->and($task->error_message)->toContain('Task resume failed:');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resolveRequiresApproval — AgentToolOperationOverride short-circuits
// ---------------------------------------------------------------------------

it('resolveRequiresApproval uses override row when present, falling back to tool default when override is null', function (): void {
    [$agentId] = seedAgent();

    // Default for StubOutputTool is `requiresApprovalByDefault: true`.
    // Override `default_requires_approval` = 0 → auto-approve.
    AgentToolOperationOverride::create([
        'agent_id'                  => $agentId,
        'tool_class'                => StubOutputTool::class,
        'operation'                 => 'default',
        'enabled'                   => null,
        'default_requires_approval' => 0,
    ]);

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_auto', 'stub_output', [])], 5, 3, 'cmp_1');
        }

        return new LLMResponse('Done after override.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Approval override test', maxSteps: 10);

    $task->refresh();
    // Override forced auto-approval → no PENDING_APPROVAL, task completes immediately.
    expect($task->status)->toBe('COMPLETED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// scheduleAutoRetry — when retry policy is configured
// ---------------------------------------------------------------------------

it('scheduleAutoRetry creates a queued retry task when error code is retryable and retry policy is configured', function (): void {
    [$agentId] = seedAgent();

    // Configure retry policy on the agent.
    $agent = Agent::find($agentId);
    $agent->retry_after_minutes = 5;
    $agent->max_retries         = 2;
    $agent->save();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        // First call: throw rate limit (so the original task fails).
        if ($callCount === 1) {
            throw new LLMRateLimitException('429 rate limit');
        }
        // Second call: succeed (so the retry task that scheduleAutoRetry starts can complete).
        return new LLMResponse('Done.', [], 5, 3, 'cmp_retry');
    });

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'      => $agentId,
        'user_id'       => $agent->user_id,
        'status'        => 'RUNNING',
        'user_prompt'   => 'Retry me',
        'step_count'    => 0,
        'max_steps'     => 10,
        'retry_count'   => 0,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Retry me']);

    try {
        $orch->tick($task->id);
        PHPUnit\Framework\Assert::fail('Expected LLMRateLimitException to propagate');
    } catch (LLMRateLimitException $e) {
        // Expected
    }

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('RATE_LIMIT');

    // A retry task should have been queued.
    $retryTask = Task::where('retry_of_task_id', $task->id)->first();
    expect($retryTask)->not->toBeNull()
        ->and($retryTask->status)->toBe('QUEUED')
        ->and($retryTask->retry_count)->toBe(1);

    // Cleanup
    $agent->retry_after_minutes = 0;
    $agent->max_retries         = 0;
    $agent->save();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('scheduleAutoRetry does NOT create a retry when agent has no retry policy', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            throw new LLMRateLimitException('429');
        }

        return new LLMResponse('Done.', [], 5, 3, 'cmp_1');
    });

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'No retry policy',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'No retry policy']);

    try {
        $orch->tick($task->id);
    } catch (LLMRateLimitException $e) {
        // Expected
    }

    // retry_after_minutes = 0, max_retries = 0 → no retry task created.
    $retryTask = Task::where('retry_of_task_id', $task->id)->first();
    expect($retryTask)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('scheduleAutoRetry does NOT exceed max_retries', function (): void {
    [$agentId] = seedAgent();

    $agent = Agent::find($agentId);
    $agent->retry_after_minutes = 1;
    $agent->max_retries         = 1;
    $agent->save();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            throw new LLMRateLimitException('429');
        }

        return new LLMResponse('Done.', [], 5, 3, 'cmp_1');
    });

    $orch = makeOrchestrator(mockDriverFactory($mock));

    // retry_count already at max → no new retry task.
    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => $agent->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Exhausted retries',
        'step_count'  => 0,
        'max_steps'   => 10,
        'retry_count' => 5, // already way past max_retries=1
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Exhausted']);

    try {
        $orch->tick($task->id);
    } catch (LLMRateLimitException $e) {
        // Expected
    }

    $retryTask = Task::where('retry_of_task_id', $task->id)->first();
    expect($retryTask)->toBeNull();

    // Cleanup
    $agent->retry_after_minutes = 0;
    $agent->max_retries         = 0;
    $agent->save();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// buildToolDefinitions — non-HasOperations tool path
// ---------------------------------------------------------------------------

it('buildToolDefinitions emits a definition for a tool without HasOperations trait', function (): void {
    [$agentId, $userId] = seedAgent();

    // Create a tool that does NOT use the HasOperations trait. It only has the
    // #[Tool] attribute and a single execute().
    $plainTool = new #[Tool(name: 'plain_tool', description: 'A tool without operations')]
    class implements ToolInterface {
        public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
        {
            return new ToolResult(true, 'plain result');
        }
        public function describeAction(array $arguments): string
        {
            return 'Run plain tool';
        }
        public function getParametersSchema(): array
        {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }
    };

    $plainClass = get_class($plainTool);

    // Enable the plain tool for this agent (must be in toolInstances AND enabledClasses).
    AgentTool::insert([
        'agent_id'   => $agentId,
        'tool_class' => $plainClass,
        'tool_name'  => 'plain_tool',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $capturedTools = [];
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function (LLMRequest $request) use (&$capturedTools) {
        $capturedTools = $request->tools;

        return new LLMResponse('Done.', [], 5, 3, 'cmp_1');
    });

    $orch = makeOrchestrator(mockDriverFactory($mock), [$plainTool]);
    $orch->start($agentId, 'Plain tool test', maxSteps: 5);

    expect($capturedTools)->toHaveCount(1);
    expect($capturedTools[0]['function']['name'])->toBe('plain_tool');
    expect($capturedTools[0]['function']['description'])->toBe('A tool without operations');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resolveToolByName — tool not found
// ---------------------------------------------------------------------------

it('resolveToolByName throws when the LLM invokes an unknown tool name', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    // The LLM hallucinates a tool name. The orchestrator catches the exception per tool call
    // and recurses to the next tick — so the LLM may be called multiple times. We use
    // andReturnUsing so Mockery doesn't enforce a strict once() expectation.
    $mock->allows('complete')->andReturn(
        new LLMResponse(null, [new DriverToolCall('call_x', 'nonexistent_tool', [])], 5, 3, 'cmp_1'),
    );

    $orch = makeOrchestrator(mockDriverFactory($mock), [new StubInputTool()]);
    $task = $orch->start($agentId, 'Unknown tool test', maxSteps: 3);

    // Verify the System Error row was written for the unknown tool.
    $errorRow = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_x')
        ->first();
    expect($errorRow)->not->toBeNull()
        ->and($errorRow->content)->toContain('System Error')
        ->and($errorRow->content)->toContain("No tool registered with name 'nonexistent_tool'");
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume() with pending_state = null recovery path
// ---------------------------------------------------------------------------

it('resume reconstructs an empty AgentState when pending_state is null but status is PENDING_APPROVAL', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        // First call: pause for approval.
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_rec', 'stub_output', [])], 5, 3, 'cmp_1');
        }
        // Second call: nothing to do — empty pending list.
        return new LLMResponse('Recovered from null pending state.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Null pending state test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // Manually clear pending_state to simulate a corrupted task.
    Task::where('id', $task->id)->update(['pending_state' => null]);

    // Resume with an empty batch — should still complete without throwing.
    $orch->resume($task->id, []);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Recovered from null pending state.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// qualifiedToolName — plugin tool name prefix
// ---------------------------------------------------------------------------

it('qualifiedToolName prepends the plugin slug when the tool belongs to a registered plugin', function (): void {
    [$agentId] = seedAgent();

    // Define a tool that we will attribute to a plugin.
    $pluginTool = new #[Tool(name: 'plugin_search', description: 'Search via plugin')]
    class implements ToolInterface {
        public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
        {
            return new ToolResult(true, 'plugin result');
        }
        public function describeAction(array $arguments): string
        {
            return 'Run plugin search';
        }
        public function getParametersSchema(): array
        {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }
    };

    $pluginToolClass = get_class($pluginTool);

    // Build a PluginInterface stub that exposes our tool class as belonging to a plugin.
    $mockPlugin = Mockery::mock(PluginInterface::class);
    $mockPlugin->allows('getName')->andReturn('Test Plugin');
    $mockPlugin->allows('tools')->andReturn([$pluginToolClass]);
    $mockPlugin->allows('autoload')->andReturn([]);
    $mockPlugin->allows('recipePaths')->andReturn([]);
    $mockPlugin->allows('schemaVersion')->andReturn(0);
    $mockPlugin->allows('migrationsPath')->andReturn(null);

    // Build a real PluginLoader and inject the plugin map via reflection (the class is final).
    $pluginLoader = new PluginLoader([sys_get_temp_dir()]);
    $reflection = new ReflectionClass($pluginLoader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($pluginLoader, ['test-plugin' => $mockPlugin]);

    // Register the tool for the agent.
    AgentTool::insert([
        'agent_id'   => $agentId,
        'tool_class' => $pluginToolClass,
        'tool_name'  => 'plugin_search',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $capturedTools = [];
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function (LLMRequest $request) use (&$capturedTools) {
        $capturedTools = $request->tools;

        return new LLMResponse('Done.', [], 5, 3, 'cmp_1');
    });

    $orch = new Orchestrator(
        driverFactory: mockDriverFactory($mock),
        toolInstances: [$pluginTool],
        pluginLoader: $pluginLoader,
    );
    $orch->start($agentId, 'Plugin tool test', maxSteps: 5);

    expect($capturedTools)->toHaveCount(1);
    expect($capturedTools[0]['function']['name'])->toBe('test-plugin:plugin_search');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resolveToolByName — strip plugin slug prefix
// ---------------------------------------------------------------------------

it('resolveToolByName strips plugin prefix from LLM tool call names', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            // LLM echoes the qualified tool name with the plugin prefix.
            return new LLMResponse(null, [new DriverToolCall('call_q', 'fancy-plugin:stub_input', [])], 5, 3, 'cmp_1');
        }
        // Subsequent calls: return text to complete the task.
        return new LLMResponse('Done.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Plugin prefix test', maxSteps: 10);

    $task->refresh();
    // Tool resolves to stub_input (after stripping prefix) and executes successfully.
    $toolCall = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCall)->not->toBeNull()
        ->and($toolCall->status)->toBe('APPROVED')
        ->and($toolCall->tool_name)->toBe('fancy-plugin:stub_input')
        ->and($toolCall->result_content)->toBe('input_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// buildMessages — tool result rows included in conversation
// ---------------------------------------------------------------------------

it('buildMessages includes tool result rows in the conversation sent to the LLM', function (): void {
    [$agentId] = seedAgent();

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 1,
        'role'         => 'assistant',
        'content'      => null,
        'tool_call_payload' => json_encode([
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'stub_input', 'arguments' => '{}']],
        ]),
    ]);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 2,
        'role'         => 'tool',
        'content'      => 'tool output',
        'tool_call_id' => 'call_a',
        'tool_name'    => 'stub_input',
    ]);

    /** @var list<array<string,mixed>> $capturedMessages */
    $capturedMessages = [];

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function (LLMRequest $request) use (&$capturedMessages) {
        $capturedMessages = $request->messages;

        return new LLMResponse('Done.', [], 5, 3, 'cmp_1');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $orch->tick($task->id);

    // Expect 3 messages: user, assistant tool_calls, tool result.
    expect($capturedMessages)->toHaveCount(3);
    expect($capturedMessages[0]['role'])->toBe('user');
    expect($capturedMessages[0]['content'])->toBe('Hello');
    expect($capturedMessages[1]['role'])->toBe('assistant');
    expect($capturedMessages[1]['content'])->toBeNull();
    expect($capturedMessages[2]['role'])->toBe('tool');
    expect($capturedMessages[2]['content'])->toBe('tool output');
    expect($capturedMessages[2]['tool_call_id'])->toBe('call_a');
    expect($capturedMessages[2]['name'])->toBe('stub_input');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// getTemperatureFromSettings — fallback when llmConfigService is null
// ---------------------------------------------------------------------------

it('resolveLlmConfig falls back to default temperature when llmConfigService is null', function (): void {
    [$agentId] = seedAgent();

    $llm  = mockLlm(new LLMResponse('Done.', [], 5, 3, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    $task = $orch->start($agentId, 'Temperature default test', maxSteps: 5);

    // Should complete without throwing even though llmConfigService is null.
    expect($task->status)->toBe('COMPLETED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// publishIntermediateState — uses ToolCallSerializer when not injected
// ---------------------------------------------------------------------------

it('publishIntermediateState falls back to a default ToolCallSerializer when none is injected', function (): void {
    [$agentId] = seedAgent();

    $mockMercure = Mockery::mock(MercurePublisherInterface::class);
    $mockMercure->allows('publish')->andReturn(true);

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_pub', 'stub_input', [])], 5, 3, 'cmp_1');
        }

        return new LLMResponse('Done.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);

    // No ToolCallSerializer injected — the Orchestrator should default-instantiate one.
    $orch = new Orchestrator(
        driverFactory: mockDriverFactory($mock),
        toolInstances: $tools,
        mercure: $mockMercure,
    );

    $task = $orch->start($agentId, 'Default serializer test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// friendlyMessageForError — context window error message includes actual limit
// ---------------------------------------------------------------------------

it('tick stores a context window error message that mentions the actual limit when error body contains one', function (): void {
    [$agentId] = seedAgent();

    $errorJson = json_encode(['error' => ['type' => 'context_window_exceeded', 'message' => 'Context window exceeds limit (4096)']]);
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andThrow(new LLMProviderException("Provider error 400: {$errorJson}"));

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'    => $agentId,
        'user_id'     => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Hi',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hi']);

    try {
        $orch->tick($task->id);
        PHPUnit\Framework\Assert::fail('Expected exception to propagate');
    } catch (LLMProviderException $e) {
        // Expected
    }

    $task->refresh();
    // The "first turn" path is taken (historyCount = 1). Verify the limit is in the message.
    expect($task->error_code)->toBe('CONTEXT_WINDOW_FIRST_TURN')
        ->and($task->error_message)->toContain('4096');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// System prompt fallback (line 160)
// ---------------------------------------------------------------------------

it('tick uses the default system prompt when agent has no system_prompt set', function (): void {
    [$agentId] = seedAgent();

    $capturedRequest = null;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function (LLMRequest $request) use (&$capturedRequest) {
        $capturedRequest = $request;

        return new LLMResponse('Done.', [], 5, 3, 'cmp_1');
    });

    // The seeded agent has no system_prompt — verify the orchestrator substitutes the default.
    $orch = makeOrchestrator(mockDriverFactory($mock));
    $orch->start($agentId, 'Default system prompt test', maxSteps: 5);

    expect($capturedRequest)->not->toBeNull()
        ->and($capturedRequest->systemPrompt)->toBe('You are a helpful AI assistant.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('tick uses the agent-provided system_prompt when set', function (): void {
    [$agentId] = seedAgent();

    // Override the agent's system_prompt to a non-default value.
    $agent = Agent::find($agentId);
    $agent->system_prompt = 'You are the test agent. Always answer with "OK".';
    $agent->save();

    $capturedRequest = null;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturnUsing(function (LLMRequest $request) use (&$capturedRequest) {
        $capturedRequest = $request;

        return new LLMResponse('OK.', [], 5, 3, 'cmp_1');
    });

    $orch = makeOrchestrator(mockDriverFactory($mock));
    $orch->start($agentId, 'Custom system prompt test', maxSteps: 5);

    expect($capturedRequest)->not->toBeNull()
        ->and($capturedRequest->systemPrompt)->toBe('You are the test agent. Always answer with "OK".');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ============================================================================
// PR 2.4 — coverage augmentation for Orchestrator::handleToolCalls and
// ::buildMessages (safety net for Phase 3.6a/3.6b split and Phase 6.4/6.5
// refactor). These tests pin the contract of the two highest-complexity
// methods in the codebase.
// ============================================================================

describe('Orchestrator::handleToolCalls — happy path', function (): void {
    it('executes an auto-approved tool inline, writes the APPROVED ToolCall, history row, and publishes intermediate state once', function (): void {
        [$agentId] = seedAgent();

        $publishCount = 0;
        $mockMercure  = Mockery::mock(MercurePublisherInterface::class);
        $mockMercure->allows('publish')->andReturnUsing(static function () use (&$publishCount): bool {
            $publishCount++;

            return true;
        });

        $callCount = 0;
        $mock      = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return new LLMResponse(null, [new DriverToolCall('call_happy', 'stub_input', [])], 5, 3, 'cmp_1');
            }

            return new LLMResponse('Done.', [], 5, 3, 'cmp_2');
        });

        $tools = [new StubInputTool()];
        enableToolsForAgent($agentId, $tools);
        $orch = new Orchestrator(
            driverFactory: mockDriverFactory($mock),
            toolInstances: $tools,
            mercure: $mockMercure,
        );
        $task = $orch->start($agentId, 'Happy path test', maxSteps: 10);

        $task->refresh();
        // Auto-approved → no PENDING_APPROVAL, loop completes in 2 turns.
        expect($task->status)->toBe('COMPLETED')
            ->and($task->step_count)->toBe(2);

        $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
        expect($toolCallRecord)->not()->toBeNull()
            ->and($toolCallRecord->status)->toBe('APPROVED')
            ->and($toolCallRecord->tool_type)->toBe('input')
            ->and($toolCallRecord->result_content)->toBe('input_result');

        // The history row carries the tool result for the LLM's next turn.
        $toolHistory = TaskHistory::where('task_id', $task->id)
            ->where('role', 'tool')
            ->where('tool_call_id', 'call_happy')
            ->first();
        expect($toolHistory)->not()->toBeNull()
            ->and($toolHistory->content)->toBe('input_result');

        // publishIntermediateState is called exactly once after handleToolCalls
        // (not again on the final tick) — confirms no duplicate publish bug.
        expect($publishCount)->toBe(1);
    })->afterEach(fn() => Spora\Core\Database::resetBootState());

    it('queues a tool that requires approval into pendingApproval, sets PENDING_APPROVAL, and does not execute', function (): void {
        [$agentId] = seedAgent();

        $mock = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->once()->andReturn(
            new LLMResponse(null, [new DriverToolCall('call_approval', 'stub_output', ['x' => 1])], 5, 3, 'cmp_1'),
        );

        $tools = [new StubOutputTool()];
        enableToolsForAgent($agentId, $tools);
        $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
        $task = $orch->start($agentId, 'Approval queue test', maxSteps: 10);

        $task->refresh();
        // Approval is required → orchestrator pauses, tick() is NOT recursed.
        expect($task->status)->toBe('PENDING_APPROVAL')
            ->and($task->pending_state)->not->toBeNull();

        // No execution happened → no APPROVED ToolCall records.
        $approvedCount = ToolCallModel::where('task_id', $task->id)
            ->where('status', 'APPROVED')
            ->count();
        expect($approvedCount)->toBe(0);

        $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
        expect($toolCallRecord->status)->toBe('PENDING_APPROVAL')
            ->and($toolCallRecord->proposed_arguments)->not->toBeNull()
            ->and($toolCallRecord->proposed_arguments)->not->toBeEmpty();

        $state = AgentState::fromJson($task->pending_state);
        expect($state->pendingToolCalls)->toHaveCount(1)
            ->and($state->pendingToolCalls[0]->toolName)->toBe('stub_output')
            ->and($state->pendingToolCalls[0]->arguments)->toBe(['x' => 1]);
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

describe('Orchestrator::handleToolCalls — disabled tool', function (): void {
    it('writes a System Error history row when the LLM calls a tool not enabled for the agent', function (): void {
        [$agentId] = seedAgent();

        $callCount = 0;
        $mock      = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return new LLMResponse(null, [new DriverToolCall('call_unauth', 'stub_input', [])], 5, 3, 'cmp_1');
            }

            return new LLMResponse('Recovered.', [], 5, 3, 'cmp_2');
        });

        // Note: enableToolsForAgent is intentionally NOT called for the agent.
        $orch = makeOrchestrator(mockDriverFactory($mock), [new StubInputTool()]);
        $task = $orch->start($agentId, 'Disabled tool test', maxSteps: 10);

        $task->refresh();
        // The error is fed back to the LLM and the loop recovers.
        expect($task->status)->toBe('COMPLETED');

        // The error message is recorded in a tool history row.
        $errorRow = TaskHistory::where('task_id', $task->id)
            ->where('role', 'tool')
            ->where('tool_call_id', 'call_unauth')
            ->first();
        expect($errorRow)->not()->toBeNull()
            ->and($errorRow->content)->toContain('System Error')
            ->and($errorRow->content)->toContain('not enabled');
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

describe('Orchestrator::handleToolCalls — validation failure', function (): void {
    it('wraps SchemaValidator exception in a Validation Error ToolResult, persists it, and does not throw or execute', function (): void {
        [$agentId] = seedAgent();

        $callCount = 0;
        $mock      = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                // LLM forgets the required 'recipient' field.
                return new LLMResponse(null, [new DriverToolCall('call_vfail', 'stub_output_with_schema', [])], 5, 3, 'cmp_1');
            }

            return new LLMResponse('Let me try again.', [], 5, 3, 'cmp_2');
        });

        $tools = [new StubOutputToolWithSchema()];
        enableToolsForAgent($agentId, $tools);
        $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
        $task = $orch->start($agentId, 'Validation failure test', maxSteps: 10);

        $task->refresh();
        // Validation failure is recoverable — the LLM is given a second chance.
        expect($task->status)->toBe('COMPLETED');

        // ToolCall row is APPROVED (not PENDING_APPROVAL) and carries the
        // validation error message in result_content.
        $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
        expect($toolCallRecord)->not()->toBeNull()
            ->and($toolCallRecord->status)->toBe('APPROVED')
            ->and($toolCallRecord->result_content)->toContain(VALIDATION_ERROR)
            ->and($toolCallRecord->result_content)->toContain('recipient');

        // A tool history row mirrors the validation error so the LLM sees it.
        $toolHistory = TaskHistory::where('task_id', $task->id)
            ->where('role', 'tool')
            ->where('tool_call_id', 'call_vfail')
            ->first();
        expect($toolHistory)->not()->toBeNull()
            ->and($toolHistory->content)->toContain(VALIDATION_ERROR);
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

describe('Orchestrator::handleToolCalls — safeExecute catches throwable', function (): void {
    it('catches the throwable from the tool, encodes it as a System Error ToolResult, and the loop continues', function (): void {
        [$agentId] = seedAgent();

        $callCount = 0;
        $mock      = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return new LLMResponse(null, [new DriverToolCall('call_throw', 'throwing_tool', [])], 5, 3, 'cmp_1');
            }

            return new LLMResponse('Recovered.', [], 5, 3, 'cmp_2');
        });

        $tools = [new ThrowingTool()];
        enableToolsForAgent($agentId, $tools);
        $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
        $task = $orch->start($agentId, 'Throw recovery test', maxSteps: 10);

        $task->refresh();
        // The throwable is caught; the loop survives.
        expect($task->status)->toBe('COMPLETED');

        $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
        expect($toolCallRecord->status)->toBe('APPROVED')
            ->and($toolCallRecord->result_content)->toContain('System Error')
            ->and($toolCallRecord->result_content)->toContain('fatal exception');

        // A tool history row carries the same message back to the LLM.
        $toolHistory = TaskHistory::where('task_id', $task->id)
            ->where('role', 'tool')
            ->where('tool_call_id', 'call_throw')
            ->first();
        expect($toolHistory)->not()->toBeNull()
            ->and($toolHistory->content)->toContain('System Error');
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

describe('Orchestrator::handleToolCalls — HasOperations operation disabled', function (): void {
    it('records a DISABLED ToolCall with tool_type=operation and writes a tool history row explaining the disabled state', function (): void {
        [$agentId] = seedAgent();

        // Disable the 'default' operation of StubInputTool for this agent.
        AgentToolOperationOverride::create([
            'agent_id'                  => $agentId,
            'tool_class'                => StubInputTool::class,
            'operation'                 => 'default',
            'enabled'                   => 0,
            'default_requires_approval' => null,
        ]);

        $callCount = 0;
        $mock      = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return new LLMResponse(null, [new DriverToolCall('call_op_disabled', 'stub_input', [])], 5, 3, 'cmp_1');
            }

            return new LLMResponse('Recovered.', [], 5, 3, 'cmp_2');
        });

        $tools = [new StubInputTool()];
        enableToolsForAgent($agentId, $tools);
        $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
        $task = $orch->start($agentId, 'Operation disabled test', maxSteps: 10);

        $task->refresh();
        expect($task->status)->toBe('COMPLETED');

        // The ToolCall record is persisted with DISABLED status and operation
        // tool_type (not input/output — the LLM's call was structurally
        // well-formed but the agent has disabled this operation).
        $toolCall = ToolCallModel::where('task_id', $task->id)->first();
        expect($toolCall)->not()->toBeNull()
            ->and($toolCall->status)->toBe('DISABLED')
            ->and($toolCall->tool_type)->toBe('operation')
            ->and($toolCall->operation)->toBe('default');

        // The history row tells the LLM the operation is disabled.
        $toolHistory = TaskHistory::where('task_id', $task->id)
            ->where('role', 'tool')
            ->where('tool_call_id', 'call_op_disabled')
            ->first();
        expect($toolHistory)->not()->toBeNull()
            ->and($toolHistory->content)->toContain("Operation 'default' is disabled");
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

describe('Orchestrator::buildMessages — empty history', function (): void {
    it('returns an empty messages array when no history rows exist', function (): void {
        [$agentId] = seedAgent();

        $task = Task::create([
            'agent_id'    => $agentId,
            'user_id'     => Agent::find($agentId)->user_id,
            'status'      => 'RUNNING',
            'user_prompt' => 'No history',
            'step_count'  => 0,
            'max_steps'   => 10,
        ]);

        $capturedMessages = null;
        $mock             = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->once()->andReturnUsing(static function (LLMRequest $request) use (&$capturedMessages) {
            $capturedMessages = $request->messages;

            return new LLMResponse('ok', [], 5, 3, 'cmp_1');
        });

        $orch = makeOrchestrator(mockDriverFactory($mock));
        $orch->tick($task->id);

        // No history rows → no projected messages. The system prompt is
        // still passed via $request->systemPrompt, but $request->messages
        // must be an empty list.
        expect($capturedMessages)->toBe([]);
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

describe('Orchestrator::buildMessages — summary substitution', function (): void {
    it('excludes the rows covered by the summary range, includes the summary row, and includes later rows', function (): void {
        [$agentId] = seedAgent();

        $task = Task::create([
            'agent_id'    => $agentId,
            'user_id'     => Agent::find($agentId)->user_id,
            'status'      => 'RUNNING',
            'user_prompt' => 'Summary test',
            'step_count'  => 0,
            'max_steps'   => 10,
        ]);

        // Pre-summary: sequences 0-2 (will be absorbed by the summary)
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Q1']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'A1']);
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 2, 'role' => 'user', 'content' => 'Q2']);
        // Summary at sequence 3 covering 0-2
        TaskHistory::create([
            'task_id'                   => $task->id,
            'sequence'                  => 3,
            'role'                      => 'summary',
            'content'                   => 'Compacted first two turns.',
            'summarized_sequence_range' => '0-2',
        ]);
        // Post-summary: sequence 4
        TaskHistory::create(['task_id' => $task->id, 'sequence' => 4, 'role' => 'user', 'content' => 'Q3']);

        $capturedMessages = null;
        $mock             = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->once()->andReturnUsing(static function (LLMRequest $request) use (&$capturedMessages) {
            $capturedMessages = $request->messages;

            return new LLMResponse('Done', [], 5, 3, 'cmp_1');
        });

        $orch = makeOrchestrator(mockDriverFactory($mock));
        $orch->tick($task->id);

        // buildMessages removes sequences in the range, keeps the summary
        // row, and includes rows with sequence > rangeEnd. So we expect:
        //   [0] = summary row
        //   [1] = Q3 (post-summary user message)
        expect($capturedMessages)->toHaveCount(2);
        expect($capturedMessages[0])->toMatchArray([
            'role'    => 'summary',
            'content' => 'Compacted first two turns.',
        ]);
        expect($capturedMessages[1])->toMatchArray([
            'role'    => 'user',
            'content' => 'Q3',
        ]);
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

describe('Orchestrator::buildMessages — assistant tool_call payload', function (): void {
    it('normalizes empty arguments array to "{}" before sending to the LLM', function (): void {
        [$agentId] = seedAgent();

        $task = Task::create([
            'agent_id'    => $agentId,
            'user_id'     => Agent::find($agentId)->user_id,
            'status'      => 'RUNNING',
            'user_prompt' => 'Empty args test',
            'step_count'  => 0,
            'max_steps'   => 10,
        ]);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
        // arguments = [] (empty array literal). buildMessages must rewrite
        // this to '{}' so strict providers (OpenAI, MiniMax, LM Studio) accept it.
        TaskHistory::create([
            'task_id'           => $task->id,
            'sequence'          => 1,
            'role'              => 'assistant',
            'content'           => null,
            'tool_call_payload' => json_encode([
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'stub_input', 'arguments' => []]],
            ]),
        ]);

        $capturedMessages = null;
        $mock             = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->once()->andReturnUsing(static function (LLMRequest $request) use (&$capturedMessages) {
            $capturedMessages = $request->messages;

            return new LLMResponse('Done', [], 5, 3, 'cmp_1');
        });

        $orch = makeOrchestrator(mockDriverFactory($mock));
        $orch->tick($task->id);

        // The assistant message is at index 1.
        expect($capturedMessages[1]['role'])->toBe('assistant')
            ->and($capturedMessages[1]['tool_calls'][0]['function']['arguments'])->toBe('{}');
    })->afterEach(fn() => Spora\Core\Database::resetBootState());

    it('preserves non-empty arguments unchanged', function (): void {
        [$agentId] = seedAgent();

        $task = Task::create([
            'agent_id'    => $agentId,
            'user_id'     => Agent::find($agentId)->user_id,
            'status'      => 'RUNNING',
            'user_prompt' => 'Non-empty args test',
            'step_count'  => 0,
            'max_steps'   => 10,
        ]);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
        // arguments = non-empty object — must be passed through unchanged.
        $originalArgs = ['recipient' => 'a@b.com', 'subject' => 'Hello'];
        TaskHistory::create([
            'task_id'           => $task->id,
            'sequence'          => 1,
            'role'              => 'assistant',
            'content'           => null,
            'tool_call_payload' => json_encode([
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'send_email', 'arguments' => $originalArgs]],
            ]),
        ]);

        $capturedMessages = null;
        $mock             = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->once()->andReturnUsing(static function (LLMRequest $request) use (&$capturedMessages) {
            $capturedMessages = $request->messages;

            return new LLMResponse('Done', [], 5, 3, 'cmp_1');
        });

        $orch = makeOrchestrator(mockDriverFactory($mock));
        $orch->tick($task->id);

        $args = $capturedMessages[1]['tool_calls'][0]['function']['arguments'];
        $decoded = is_string($args) ? json_decode($args, true) : $args;
        expect($decoded)->toBe($originalArgs);
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

describe('Orchestrator::buildMessages — tool role', function (): void {
    it('emits {role: tool, tool_call_id, name, content} and strips the _seq scaffolding key from every message', function (): void {
        [$agentId] = seedAgent();

        $task = Task::create([
            'agent_id'    => $agentId,
            'user_id'     => Agent::find($agentId)->user_id,
            'status'      => 'RUNNING',
            'user_prompt' => 'Tool role test',
            'step_count'  => 0,
            'max_steps'   => 10,
        ]);

        TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Hello']);
        TaskHistory::create([
            'task_id'      => $task->id,
            'sequence'     => 1,
            'role'         => 'tool',
            'content'      => 'tool output content',
            'tool_call_id' => 'call_xyz',
            'tool_name'    => 'stub_input',
        ]);

        $capturedMessages = null;
        $mock             = Mockery::mock(LLMDriverInterface::class);
        $mock->allows('complete')->once()->andReturnUsing(static function (LLMRequest $request) use (&$capturedMessages) {
            $capturedMessages = $request->messages;

            return new LLMResponse('Done', [], 5, 3, 'cmp_1');
        });

        $orch = makeOrchestrator(mockDriverFactory($mock));
        $orch->tick($task->id);

        // Expect 2 messages: user + tool.
        expect($capturedMessages)->toHaveCount(2);

        // The tool message has the OpenAI-compatible shape.
        $toolMsg = $capturedMessages[1];
        expect($toolMsg)->toMatchArray([
            'role'         => 'tool',
            'tool_call_id' => 'call_xyz',
            'name'         => 'stub_input',
            'content'      => 'tool output content',
        ]);

        // The _seq scaffolding key must NOT leak into the final output.
        expect($toolMsg)->not->toHaveKey('_seq');
        expect($capturedMessages[0])->not->toHaveKey('_seq');
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});
