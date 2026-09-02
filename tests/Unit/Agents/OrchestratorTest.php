<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ValueObjects\AgentState;
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
use Spora\Models\MediaAsset;
use Spora\Models\PrincipalPreference;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Plugins\PluginInterface;
use Spora\Plugins\PluginLoader;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\ToolCallSerializer;
use Spora\Tools\Attributes\Tool;
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
): Orchestrator {
    return new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(
            toolInstances: $toolInstances,
            logger: $logger,
        ),
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
    $mock->allows('supportsImageInput')->andReturn(false);

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
        'principal_id' => null,
        'name'          => 'Test Global Config',
        'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'      => json_encode(['api_key' => 'test']),
        'is_global'     => true,
        'is_default'    => true,
        'context_window' => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
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

it('start creates a QUEUED Task and seeds user history row', function (): void {
    [$agentId] = seedAgent();

    $llm = Mockery::mock(LLMDriverInterface::class);
    $llm->allows('complete')->never();

    $orch = makeOrchestrator(mockDriverFactory($llm));
    $task = $orch->start($agentId, PROMPT_HELLO, maxSteps: 5);

    expect($task->status)->toBe('QUEUED')
        ->and($task->user_prompt)->toBe(PROMPT_HELLO)
        ->and($task->max_steps)->toBe(5);

    $history = TaskHistory::where('task_id', $task->id)->orderBy('sequence')->get();
    expect($history->first()->role)->toBe('user')
        ->and($history->first()->content)->toBe(PROMPT_HELLO);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('after tick the started task reaches COMPLETED', function (): void {
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
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Done.');
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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

it('resume queues the approved OutputTool so the next tick executes it and appends history', function (): void {
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
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // New batch format: list<{provider_call_id, arguments}>.
    $orch->resume($task->id, [['provider_call_id' => 'call_r', 'decision' => 'approve', 'arguments' => ['x' => 99]]]);
    claimAndTick($orch, $task->id);

    $task->refresh();
    // 2 LLM turns: tick 1 (tool call paused) + tick 2 (after resume).
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2)
        ->and($task->final_response)->toBe('Resumed.');

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->approved_arguments)->toBe(['x' => 99]);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('resume throws when task is not PENDING_APPROVAL', function (): void {
    [$agentId] = seedAgent();

    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // Human forgot the required 'recipient' field — schema validation must catch this gracefully now.
    $orch->resume($task->id, [['provider_call_id' => 'call_schema', 'decision' => 'approve', 'arguments' => []]]);
    claimAndTick($orch, $task->id);

    $toolCall = ToolCallModel::where('provider_call_id', 'call_schema')->first();
    expect($toolCall->status)->toBe('APPROVED')
        ->and($toolCall->result_content)->toContain(VALIDATION_ERROR);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('resume routes mixed approve and reject decisions atomically', function (): void {
    [$agentId, $userId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        return $callCount === 1
            ? new LLMResponse(null, [
                new DriverToolCall('call_approved', 'stub_output', ['x' => 1]),
                new DriverToolCall('call_rejected', 'stub_output', ['x' => 2]),
            ], 5, 3, 'cmp_1')
            : new LLMResponse('Decisions applied.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Mixed decisions', maxSteps: 10);
    claimAndTick($orch, $task->id);

    $orch->resume($task->id, [
        ['provider_call_id' => 'call_approved', 'decision' => 'approve', 'arguments' => ['x' => 99]],
        ['provider_call_id' => 'call_rejected', 'decision' => 'reject', 'reason' => 'Wrong target'],
    ]);
    claimAndTick($orch, $task->id);

    $approved = ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_approved')->first();
    $rejected = ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_rejected')->first();
    $history = TaskHistory::where('task_id', $task->id)->where('tool_call_id', 'call_rejected')->first();

    expect($approved->status)->toBe('APPROVED')
        ->and($approved->executed_at)->not->toBeNull()
        ->and($rejected->status)->toBe('REJECTED')
        ->and($rejected->rejected_by)->toBe($userId)
        ->and($rejected->reject_reason)->toBe('Wrong target')
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($history->content)->toBe('Action rejected by user: Wrong target');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('resume keeps a task pending after a reject-only partial decision', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(new LLMResponse(null, [
        new DriverToolCall('call_rejected', 'stub_output', ['x' => 1]),
        new DriverToolCall('call_pending', 'stub_output', ['x' => 2]),
    ], 5, 3, 'cmp_1'));

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Partial reject', maxSteps: 10);
    claimAndTick($orch, $task->id);

    $orch->resume($task->id, [[
        'provider_call_id' => 'call_rejected',
        'decision' => 'reject',
    ]]);

    $task->refresh();
    $state = AgentState::fromJson((string) $task->pending_state);
    expect($task->status)->toBe('PENDING_APPROVAL')
        ->and($state->pendingToolCalls)->toHaveCount(1)
        ->and($state->pendingToolCalls[0]->providerCallId)->toBe('call_pending')
        ->and(ToolCallModel::where('provider_call_id', 'call_rejected')->value('reject_reason'))->toBe('User rejected');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('resume rejects an unknown provider call without mutating pending rows', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(new LLMResponse(null, [
        new DriverToolCall('call_known', 'stub_output', []),
    ], 5, 3, 'cmp_1'));

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Unknown decision', maxSteps: 10);
    claimAndTick($orch, $task->id);

    expect(fn() => $orch->resume($task->id, [[
        'provider_call_id' => 'call_unknown',
        'decision' => 'reject',
    ]]))->toThrow(InvalidArgumentException::class, 'not pending approval');

    expect(ToolCallModel::where('provider_call_id', 'call_known')->value('status'))->toBe('PENDING_APPROVAL')
        ->and(TaskHistory::where('task_id', $task->id)->where('role', 'tool')->count())->toBe(0);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('resume validates every decision field before changing state', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(new LLMResponse(null, [
        new DriverToolCall('call_known', 'stub_output', []),
    ], 5, 3, 'cmp_1'));

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Invalid decisions', maxSteps: 10);
    claimAndTick($orch, $task->id);

    $invalidDecisions = [
        ['invalid item'],
        [[]],
        [['provider_call_id' => 'call_known', 'decision' => 'skip']],
        [['provider_call_id' => 'call_known', 'decision' => 'approve']],
        [['provider_call_id' => 'call_known', 'decision' => 'reject', 'reason' => []]],
    ];

    foreach ($invalidDecisions as $decisions) {
        expect(fn() => $orch->resume($task->id, $decisions))->toThrow(InvalidArgumentException::class);
    }

    expect(ToolCallModel::where('provider_call_id', 'call_known')->value('status'))->toBe('PENDING_APPROVAL');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('resume rolls back when a state call no longer has a pending database row', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(new LLMResponse(null, [
        new DriverToolCall('call_drifted', 'stub_output', []),
    ], 5, 3, 'cmp_1'));

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Drifted decision', maxSteps: 10);
    claimAndTick($orch, $task->id);
    ToolCallModel::where('provider_call_id', 'call_drifted')->update(['status' => 'REJECTED']);

    expect(fn() => $orch->resume($task->id, [[
        'provider_call_id' => 'call_drifted',
        'decision' => 'reject',
    ]]))->toThrow(InvalidArgumentException::class, 'not pending approval');

    expect(TaskHistory::where('task_id', $task->id)->where('role', 'tool')->count())->toBe(0);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// reject()
// ---------------------------------------------------------------------------

it('reject injects rejection into history and queues the task for the next tick', function (): void {
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
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    $orch->reject($task->id, 'Too risky');
    claimAndTick($orch, $task->id);

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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
    $task = $orch->start($agentId, 'Log dispatch test', maxSteps: 10);
    claimAndTick($orch, $task->id);
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
    $task = $orch->start($agentId, 'Log failure test', maxSteps: 10);
    claimAndTick($orch, $task->id);
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
    $task = $orch->start($agentId, 'Log exception test', maxSteps: 10);
    claimAndTick($orch, $task->id);
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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => $this->createUserPrincipal($userId),
        'name'                 => 'Agent Without Config',
        'llm_driver_config_id' => null,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    // start() only enqueues — the config is resolved on the first tick.
    $task = $orch->start($agent->id, 'Hello', maxSteps: 5);

    expect(fn() => claimAndTick($orch, $task->id))
        ->toThrow(RuntimeException::class, 'No LLM configuration set for this agent');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('resolveLlmConfig uses user preference when agent has no llm_driver_config_id', function (): void {
    [$agentId, $userId] = seedAgent();

    // Create a config and set it as user preference
    $config = LLMDriverConfiguration::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name' => USER_PREFERRED_CONFIG_NAME,
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-test', 'model' => 'gpt-4o']),
        'is_global' => false,
    ]);
    $config->context_window = 64000;
    $config->max_tokens_output = 2048;
    $config->save();

    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'preferred_llm_config_id' => $config->id,
    ]);

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    // Should not throw - uses user preference
    $task = $orch->start($agentId, 'Hello', maxSteps: 5);
    claimAndTick($orch, $task->id);

    expect($task->refresh()->status)->toBe('COMPLETED');

    // Cleanup
    PrincipalPreference::where('principal_id', $userId)->delete();
    LLMDriverConfiguration::where('id', $config->id)->delete();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('resolveLlmConfig prefers user preference over global default', function (): void {
    [$agentId, $userId] = seedAgent();

    // Create a global default config
    $globalConfig = LLMDriverConfiguration::create([
        'principal_id' => null,
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
        'principal_id' => $this->createUserPrincipal($userId),
        'name' => USER_PREFERRED_CONFIG_NAME,
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-pref', 'model' => 'gpt-4o']),
        'is_global' => false,
    ]);
    $prefConfig->context_window = 64000;
    $prefConfig->max_tokens_output = 2048;
    $prefConfig->save();

    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'preferred_llm_config_id' => $prefConfig->id,
    ]);

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    // Should use user preference, not global default
    $task = $orch->start($agentId, 'Hello', maxSteps: 5);
    claimAndTick($orch, $task->id);

    expect($task->refresh()->status)->toBe('COMPLETED');

    // Cleanup
    PrincipalPreference::where('principal_id', $userId)->delete();
    // Detach references first (FK from principal_preferences.preferred_llm_config_id).
    PrincipalPreference::whereIn('preferred_llm_config_id', [$globalConfig->id, $prefConfig->id])->delete();

    LLMDriverConfiguration::whereIn('id', [$globalConfig->id, $prefConfig->id])->delete();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('resolveLlmConfig uses agent-specific config when set', function (): void {
    [$agentId, $userId] = seedAgent();

    // Create user preference config
    $prefConfig = LLMDriverConfiguration::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name' => USER_PREFERRED_CONFIG_NAME,
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-pref', 'model' => 'gpt-4o']),
        'is_global' => false,
    ]);
    $prefConfig->context_window = 64000;
    $prefConfig->max_tokens_output = 2048;
    $prefConfig->save();

    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'preferred_llm_config_id' => $prefConfig->id,
    ]);

    // Create agent-specific config
    $agentConfig = LLMDriverConfiguration::create([
        'principal_id' => $this->createUserPrincipal($userId),
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
    claimAndTick($orch, $task->id);

    expect($task->refresh()->status)->toBe('COMPLETED');

    // Cleanup
    PrincipalPreference::where('principal_id', $userId)->delete();
    // Detach references before deleting LLMDriverConfigurations (FK from
    // principal_preferences.preferred_llm_config_id and from agents.llm_driver_config_id).
    PrincipalPreference::whereIn('preferred_llm_config_id', [$prefConfig->id, $agentConfig->id])->delete();
    Agent::where('id', $agentId)->update(['llm_driver_config_id' => null]);
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
        'principal_id' => createUserPrincipalPublic($userA),
        'name' => 'User A Config',
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-usera', 'model' => 'gpt-4o']),
        'is_global' => false,
        'context_window' => 64000,
        'max_tokens_output' => 2048,
    ]);

    // User A sets preference for their own config
    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userA),
        'preferred_llm_config_id' => $configA->id,
    ]);

    // User B creates their own config
    $configB = LLMDriverConfiguration::create([
        'principal_id' => createUserPrincipalPublic($userB),
        'name' => 'User B Config',
        'driver_class' => OPENAI_COMPATIBLE_DRIVER,
        'settings' => json_encode(['api_key' => 'sk-userb', 'model' => 'gpt-4o']),
        'is_global' => false,
        'context_window' => 32000,
        'max_tokens_output' => 1024,
    ]);

    // User B sets preference for their own config
    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userB),
        'preferred_llm_config_id' => $configB->id,
    ]);

    // Create agents for both users
    $agentA = Agent::create([
        'principal_id' => createUserPrincipalPublic($userA),
        'name' => 'User A Agent',
        'llm_driver_config_id' => null,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $agentB = Agent::create([
        'principal_id' => createUserPrincipalPublic($userB),
        'name' => 'User B Agent',
        'llm_driver_config_id' => null,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    // User A's agent should get User A's config (via user_id = A in preference lookup)
    $llmA = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orchA = makeOrchestrator(mockDriverFactory($llmA));
    $taskA = $orchA->start($agentA->id, 'Hello', maxSteps: 5);
    claimAndTick($orchA, $taskA->id);
    expect($taskA->refresh()->status)->toBe('COMPLETED');

    // User B's agent should get User B's config (via user_id = B in preference lookup)
    $llmB = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_2'));
    $orchB = makeOrchestrator(mockDriverFactory($llmB));
    $taskB = $orchB->start($agentB->id, 'Hello', maxSteps: 5);
    claimAndTick($orchB, $taskB->id);
    expect($taskB->refresh()->status)->toBe('COMPLETED');

    // Cleanup
    PrincipalPreference::whereIn('principal_id', [$userA, $userB])->delete();
    // Detach references first (FK from principal_preferences.preferred_llm_config_id).
    PrincipalPreference::whereIn('preferred_llm_config_id', [$configA->id, $configB->id])->delete();

    // Delete tasks → agents → LLM configs in that order to satisfy the
    // tasks.agent_id and agents.llm_driver_config_id FK chains.
    Task::whereIn('agent_id', [$agentA->id, $agentB->id])->delete();
    Agent::whereIn('id', [$agentA->id, $agentB->id])->delete();
    LLMDriverConfiguration::whereIn('id', [$configA->id, $configB->id])->delete();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// publishIntermediateState — Mercure duplicate fix
// ---------------------------------------------------------------------------

it('publishes intermediate state exactly once when tools are auto-approved', function (): void {
    [$agentId] = seedAgent();

    $publishCount = 0;
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    $mockMercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mockMercure->allows('publishForPrincipal')
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
        mockDriverFactory($mock),
        new OrchestratorConfig(
            toolInstances: $tools,
            mercure: $mockMercure,
        ),
    );

    $task = $orch->start($agentId, 'Auto approve test', maxSteps: 10);
    claimAndTick($orch, $task->id);

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
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    $mockMercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mockMercure->allows('publishForPrincipal')
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
        mockDriverFactory($mock),
        new OrchestratorConfig(
            toolInstances: $tools,
            mercure: $mockMercure,
        ),
    );

    $task = $orch->start($agentId, 'Approval test', maxSteps: 10);
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // When approval is needed, publishIntermediateState is called exactly once (line 821)
    expect($publishCount)->toBe(1);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('publishes the final tool batch even when status flips to ABORTED post-batch', function (): void {
    // Regression: when a user abort lands while a tool is executing, the
    // worker accepts the in-flight tool completion (so the result lands
    // in the DB) but then bails before kicking the next tick. Without an
    // explicit publish here, the chat would only see the ABORTED banner
    // and have to reload the page to see the just-completed tool output.
    //
    // We drive this through reflection on the private handleToolCalls()
    // because the public tick() entry point bails at
    // lockRunningTaskForTick() the moment status flips to ABORTED, which
    // never exercises the post-batch publish-then-check ordering this
    // regression guards against. The reflection path lets us pin the
    // invariant directly.
    [$agentId] = seedAgent();

    $publishCount = 0;
    $capturedStatuses = [];
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    $mockMercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mockMercure->allows('publishForPrincipal')
        ->andReturnUsing(static function (int $taskId, int $userId, array $data) use (&$publishCount, &$capturedStatuses): bool {
            $publishCount++;
            $capturedStatuses[] = $data['status'] ?? null;

            return true;
        });

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);

    $orch = new Orchestrator(
        mockDriverFactory(Mockery::mock(LLMDriverInterface::class)),
        new OrchestratorConfig(
            toolInstances: $tools,
            mercure: $mockMercure,
        ),
    );

    // Pull a RUNNING task out of the DB and flip its status to ABORTED
    // so the post-batch bail will fire on its first read.
    $task = Task::create([
        'trigger_user_id' => Agent::find($agentId)->user_id,
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'abort race test',
        'max_steps'   => 10,
    ]);
    Capsule::table('tasks')->where('id', $task->id)->update(['status' => 'ABORTED']);

    // Invoke the private handleToolCalls() with a single tool call so
    // the post-batch branch runs. The tool completes cleanly, the
    // publish-then-bail ordering kicks in, and we observe the publish.
    $agent = Agent::find($agentId);
    $handleToolCalls = new ReflectionMethod(Spora\Agents\TickPhaseRunner::class, 'handleToolCalls');
    $handleToolCalls->setAccessible(true);

    $runnerProp = new ReflectionProperty($orch, 'tickPhaseRunner');
    /** @var Spora\Agents\TickPhaseRunner $runner */
    $runner = $runnerProp->getValue($orch);

    $handleToolCalls->invoke($runner, $task, $agent, [new DriverToolCall('call_1', 'stub_input', [])], [StubInputTool::class]);

    // The publish-then-bail ordering must produce ONE Mercure publish
    // even on the abort path. Without the fix this would be 0 — the
    // bail happens BEFORE the publish, leaving the chat to discover
    // the just-completed tool only on reload. The publish payload
    // reflects the freshly-fetched row (status=ABORTED here), so
    // Mercure consumers see the truth without a separate reconcile
    // step.
    expect($publishCount)->toBe(1)
        ->and($capturedStatuses[0] ?? null)->toBe('ABORTED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('TickPhaseRunner.runTick discards the LLM response when status flips to ABORTED while waiting', function (): void {
    // Regression: the agent loop is mid-LLM-call when the user clicks
    // Abort. The LLM request had already been dispatched, so the response
    // arrives ~seconds later. Without a status re-check between
    // dispatchLlmRequest() returning and handleTickLlmResponse() being
    // called, the loop would treat the response as authoritative and
    // call completeTaskWithResponse() — flipping status back to
    // COMPLETED and discarding the abort.
    //
    // The user's report on spora/tasks/37 had this exact signature:
    // abort lands while read_url is in flight, the recursive tick
    // fires LLM #2, the abort hits during LLM #2's HTTP request, and
    // the response drives the task to COMPLETED against the user's
    // intent. The fix adds a post-LLM status re-check inside runTick().
    [$agentId] = seedAgent();

    $capturedStatuses = [];
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    $mockMercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mockMercure->allows('publishForPrincipal')
        ->andReturnUsing(static function (int $taskId, int $userId, array $data) use (&$capturedStatuses): bool {
            $capturedStatuses[] = $data['status'] ?? null;

            return true;
        });

    $llmCallCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$llmCallCount) {
        $llmCallCount++;

        return new LLMResponse('Final answer — task done.', [], 5, 1, 'cmp_post_abort');
    });
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');

    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn($mock);

    $llmConfig = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'           => 'Test Global Config',
        'driver_class'   => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'       => json_encode(['api_key' => 'test']),
        'is_global'      => true,
        'is_default'     => true,
        'context_window' => 128000,
    ]);

    $agent = Agent::where('id', $agentId)->first();
    $agent->llm_driver_config_id = $llmConfig->id;
    $agent->save();

    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($agent->user_id),
        'trigger_user_id' => $agent->user_id,
        'agent_id'    => $agent->id,
        'status'      => 'RUNNING',
        'user_prompt' => 'abort race',
        'step_count'  => 0,
        'max_steps'   => 5,
    ]);

    // The first LLM call dispatches OK. Once the mock returns, the
    // runTick function must observe that the task has been flipped
    // to ABORTED in the meantime and discard the response.
    //
    // We simulate the post-LLM-call status flip by hoisting a
    // post-LLM-mock callback that flips status before handleTickLlmResponse.
    // The simplest path is to use the tickOnce orchestration: dispatch
    // LLM once, then status=ABORTED, then verify runTick returns without
    // calling completeTask.
    //
    // Override the driver factory's mock: install a side-effect that
    // flips status AFTER the LLM responds.
    $flipStatus = function () use ($task): void {
        Capsule::table('tasks')->where('id', $task->id)->update([
            'status'     => 'ABORTED',
            'data'       => json_encode(['aborted_at' => date('Y-m-d H:i:s')], JSON_THROW_ON_ERROR),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    };

    $flipMock = Mockery::mock(LLMDriverInterface::class);
    $flipMock->allows('complete')->andReturnUsing(static function () use ($flipStatus) {
        // Flip to ABORTED immediately after the LLM call completes —
        // simulates the "abort lands while waiting on the LLM provider"
        // race we are guarding against.
        $flipStatus();

        return new LLMResponse('Should never reach the chat.', [], 5, 1, 'cmp_late');
    });
    $flipMock->allows('getProviderName')->andReturn('mock');
    $flipMock->allows('getModelName')->andReturn('mock-model');

    $flipDriverFactory = Mockery::mock(DriverFactory::class);
    $flipDriverFactory->allows('makeFromAgent')->andReturn($flipMock);

    $orch = new Orchestrator(
        $flipDriverFactory,
        new OrchestratorConfig(
            toolInstances: [],
            mercure: $mockMercure,
        ),
    );

    $orch->tick($task->id);

    $fresh = Task::find($task->id);
    // The abort landed during the LLM call — the loop must NOT have
    // flipped status back to COMPLETED on the way out.
    expect($fresh->status)->toBe('ABORTED')
        ->and($fresh->final_response)->toBeNull();

    // No new history rows were appended: handleTickLlmResponse was
    // bailed out before completeTaskWithResponse could write a row.
    $newHistoryRows = TaskHistory::where('task_id', $task->id)
        ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-1 hour')))
        ->count();
    expect($newHistoryRows)->toBe(0);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// NO_LLM_CONFIGURATION error handling — task state persistence
// ---------------------------------------------------------------------------

test('tick sets NO_LLM_CONFIGURATION error code and message when resolveLlmConfig throws', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('no-config@example.com', TEST_PASSWORD, 'Noconfig');

    // Agent with no LLM config and no global default — resolveLlmConfig() will throw.
    $agent = Agent::create([
        'principal_id' => $this->createUserPrincipal($userId),
        'name'                 => 'Agent Without Config',
        'llm_driver_config_id' => null,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $llm = mockLlm(new LLMResponse('Done', [], 10, 5, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    // start() only enqueues; the missing config surfaces on the first tick,
    // which throws inside the transaction.
    $task = $orch->start($agent->id, 'Hello', maxSteps: 5);

    try {
        claimAndTick($orch, $task->id);
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
    $orch = makeOrchestrator(mockDriverFactory($llm), [], null);

    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
    Capsule::connection()->enableQueryLog();
    Capsule::connection()->flushQueryLog();

    $orch = makeOrchestrator(
        Mockery::mock(DriverFactory::class),
        [new StubInputTool(), new StubOutputTool()],
    );

    $reflection = new ReflectionClass($orch);
    $method = $reflection->getMethod('buildToolDefinitions');

    // Call it with only StubInputTool enabled
    $method->invoke($orch, [StubInputTool::class], $agentId, null);

    $logs = Capsule::connection()->getQueryLog();
    Capsule::connection()->disableQueryLog();

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

it('continue() throws InvalidTaskTransitionException when source status is not in the accepted list', function (): void {
    [$agentId] = seedAgent();

    $llm  = mockLlm(new LLMResponse('Done.', [], 5, 3, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'awaiting approval',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    expect(fn() => $orch->continue($task->id, 'new prompt'))
        ->toThrow(InvalidTaskTransitionException::class, 'Can only continue completed, failed, aborted, or running tasks.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('continue() overrides max_steps when additionalSteps is supplied', function (): void {
    [$agentId] = seedAgent();

    $llm  = mockLlm(new LLMResponse('Continued.', [], 5, 3, 'cmp_1'));
    $orch = makeOrchestrator(mockDriverFactory($llm));

    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
        'status'      => 'COMPLETED',
        'user_prompt' => PROMPT_ORIGINAL,
        'step_count'  => 5,
        'max_steps'   => 10,
    ]);

    $continuedTask = $orch->continue($task->id, PROMPT_CONTINUED, additionalSteps: 25);
    claimAndTick($orch, $continuedTask->id);
    $continuedTask->refresh();

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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED');

    // The error message should be in the tool history (as a 'tool' role row).
    // As of the resume-auth fix, the normal-tick catch block emits a clear
    // authorization message instead of the misleading "System Error:" prefix
    // — see TickPhaseRunner::handleToolCalls() for the wording.
    $errorHistory = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_unauth')
        ->first();
    expect($errorHistory)->not->toBeNull()
        ->and($errorHistory->content)->toContain("Tool 'stub_input' is not enabled for this agent.")
        ->and($errorHistory->content)->not->toContain('System Error');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume() — dangling PENDING_APPROVAL records (DB/State drift)
//
// "Dangling" here means: a ToolCall row exists in the DB with status
// PENDING_APPROVAL but is NOT present in the saved `pending_state` JSON.
// That's a state/DB drift case (concurrency bugs, manual edits, retries
// from older code paths) — the resume path marks those rows REJECTED.
//
// Partial-approval (a tool call IS in `pending_state` but the user didn't
// include it in the approval batch) is a separate case handled below —
// those rows stay PENDING_APPROVAL so the user can decide on a later
// round instead of being silently executed with the LLM's original
// arguments.
// ---------------------------------------------------------------------------

it('resume cleans up dangling PENDING_APPROVAL rows that drift from the saved state', function (): void {
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
    claimAndTick($orch, $task->id);

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
    $orch->resume($task->id, [['provider_call_id' => 'call_a', 'decision' => 'approve', 'arguments' => ['x' => 99]]]);
    claimAndTick($orch, $task->id);

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
// resume() — partial-approval semantics
//
// "Partial approval" = the user explicitly approves some of the pending
// tool calls in the batch but not others. The previous implementation
// fell through `$approvedMap[$id] ?? $pendingToolCall->arguments` and
// silently executed the un-approved calls with the LLM's original
// arguments — that's the "the other approval was dropped" bug. The
// new behaviour: un-approved tool calls stay PENDING_APPROVAL, the
// task stays PENDING_APPROVAL, `pending_state` is rewritten with only
// the remaining calls, and the LLM is not called this round.
// ---------------------------------------------------------------------------

it('partial approval in worker mode defers the approved tool and keeps the un-approved set pending for a later round', function (): void {
    [$agentId] = seedAgent();

    $llm = Mockery::mock(LLMDriverInterface::class);
    // Worker mode: the LLM must NOT be called during the resume HTTP path.
    // The worker daemon calls it later when it picks up the QUEUED task.
    $llm->shouldNotReceive('complete');

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);

    // Seed a RUNNING task with two parallel PENDING_APPROVAL rows so
    // we're testing the resume() path in isolation. (Driving an LLM
    // round-trip in worker mode requires going through the daemon's
    // QUEUED→RUNNING dance, which is exercised in the worker-mode
    // pickup e2e test below.)
    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'Partial approval worker',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Partial approval worker']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => null]);

    $state = new AgentState(
        taskId: $task->id,
        agentId: $agentId,
        pendingToolCalls: [
            new DriverToolCall('call_a', 'stub_output', ['x' => 1]),
            new DriverToolCall('call_b', 'stub_output', ['x' => 2]),
        ],
        messageSnapshot: [],
        stepCount: 1,
        maxSteps: 10,
        pausedAt: date('Y-m-d\TH:i:s\Z'),
    );
    Task::where('id', $task->id)->update(['pending_state' => $state->toJson()]);

    ToolCallModel::insert([
        [
            'task_id'                  => $task->id,
            'agent_id'                 => $agentId,
            'provider_call_id'         => 'call_a',
            'tool_name'                => 'stub_output',
            'tool_class'               => StubOutputTool::class,
            'tool_type'                => 'output',
            'operation'                => 'default',
            'operation_description'    => 'a',
            'status'                   => 'PENDING_APPROVAL',
            'proposed_arguments'       => json_encode(['x' => 1]),
        ],
        [
            'task_id'                  => $task->id,
            'agent_id'                 => $agentId,
            'provider_call_id'         => 'call_b',
            'tool_name'                => 'stub_output',
            'tool_class'               => StubOutputTool::class,
            'tool_type'                => 'output',
            'operation'                => 'default',
            'operation_description'    => 'b',
            'status'                   => 'PENDING_APPROVAL',
            'proposed_arguments'       => json_encode(['x' => 2]),
        ],
    ]);

    $orch = makeOrchestrator(
        mockDriverFactory($llm),
        $tools,
    );

    $orch->resume($task->id, [['provider_call_id' => 'call_a', 'decision' => 'approve', 'arguments' => ['x' => 99]]]);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // call_a row: APPROVED + executed_at=NULL (sentinel — wait for the daemon)
    $rowA = ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_a')->first();
    expect($rowA)->not->toBeNull()
        ->and($rowA->status)->toBe('APPROVED')
        ->and($rowA->executed_at)->toBeNull()
        ->and($rowA->approved_arguments)->toBe(['x' => 99]);
    $rowB = ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_b')->first();
    expect($rowB)->not->toBeNull()
        ->and($rowB->status)->toBe('PENDING_APPROVAL')
        ->and($rowB->executed_at)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume() — two-round sequence completes the partially-approved set
// ---------------------------------------------------------------------------

it('a second approval round finishes a partially-approved set without re-prompting for the already-approved tool', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;

        if ($callCount === 1) {
            // Round 1: pause with two parallel tool calls.
            return new LLMResponse(null, [
                new DriverToolCall('call_a', 'stub_output', ['x' => 1]),
                new DriverToolCall('call_b', 'stub_output', ['x' => 2]),
            ], 5, 3, 'cmp_1');
        }

        if ($callCount === 2) {
            // Round 2: after both have been executed, the LLM sees the
            // results and produces a final text response.
            return new LLMResponse('Both approved.', [], 5, 3, 'cmp_2');
        }

        throw new RuntimeException("Unexpected LLM call #$callCount");
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Two-round approval', maxSteps: 10);
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    // Round 1: approve only call_a.
    $orch->resume($task->id, [['provider_call_id' => 'call_a', 'decision' => 'approve', 'arguments' => ['x' => 99]]]);
    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');
    expect($callCount)->toBe(1);

    // Round 2: approve call_b — both approved rows run on the next tick.
    $orch->resume($task->id, [['provider_call_id' => 'call_b', 'decision' => 'approve', 'arguments' => ['x' => 77]]]);
    claimAndTick($orch, $task->id);
    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($callCount)->toBe(2)
        ->and($task->step_count)->toBe(2)
        ->and($task->final_response)->toBe('Both approved.');

    $rowA = ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_a')->first();
    $rowB = ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_b')->first();
    expect($rowA->status)->toBe('APPROVED')
        ->and($rowA->executed_at)->not->toBeNull();
    expect($rowB->status)->toBe('APPROVED')
        ->and($rowB->executed_at)->not->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// runTick() — executes approved-but-pending tools before the LLM round-trip
// ---------------------------------------------------------------------------

it('runTick() picks up APPROVED + executed_at NULL rows and runs them before the LLM call (worker-mode pickup path)', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        return new LLMResponse('Done after pickup.', [], 5, 3, 'cmp_1');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);

    // Seed a RUNNING task with one pre-existing APPROVED row that's
    // waiting for execution (the worker-mode resume sentinel shape).
    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Worker pickup test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Worker pickup test']);

    ToolCallModel::create([
        'task_id'               => $task->id,
        'agent_id'              => $agentId,
        'provider_call_id'      => 'call_pickup',
        'tool_name'             => 'stub_output',
        'tool_class'            => StubOutputTool::class,
        'tool_type'             => 'output',
        'operation'             => 'default',
        'operation_description' => 'Pickup',
        'status'                => 'APPROVED',
        'proposed_arguments'    => json_encode(['x' => 42]),
        'approved_arguments'    => json_encode(['x' => 42]),
        // executed_at intentionally NULL — the sentinel.
    ]);

    $orch->tick($task->id);

    // tick() must have run the approved tool before the LLM call.
    $row = ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_pickup')->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('APPROVED')
        ->and($row->executed_at)->not->toBeNull()
        ->and($row->result_content)->not->toBeNull();

    // The LLM saw the tool result on its round-trip.
    expect($callCount)->toBe(1);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Done after pickup.');

    // The tool result must be present in history so the LLM could
    // have seen it on the round-trip.
    $toolHistory = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_pickup')
        ->first();
    expect($toolHistory)->not->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// runTick() worker-mode pickup — failure paths
//
// When the daemon's tick() picks up an APPROVED-but-not-yet-executed row,
// the row may still hit SchemaValidator failure (approved_arguments don't
// match the schema) or tool execution failure (the tool throws). Both
// must record the failure result and stamp executed_at so the LLM's
// next round-trip sees the error instead of retrying forever.
// ---------------------------------------------------------------------------

it('runTick() worker-mode pickup records a Validation Error when the approved arguments fail the schema', function (): void {
    [$agentId] = seedAgent();

    $llmCalls = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$llmCalls) {
        $llmCalls++;
        return new LLMResponse('Done after validation error.', [], 5, 3, 'cmp_vfail');
    });

    $tools = [new StubOutputToolWithSchema()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);

    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Validation failure in pickup',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Validation failure in pickup']);

    // Approved args intentionally lack the required 'recipient' field.
    ToolCallModel::create([
        'task_id'               => $task->id,
        'agent_id'              => $agentId,
        'provider_call_id'      => 'call_vfail_pickup',
        'tool_name'             => 'stub_output_with_schema',
        'tool_class'            => StubOutputToolWithSchema::class,
        'tool_type'             => 'output',
        'operation'             => 'default',
        'operation_description' => 'Pickup validation failure',
        'status'                => 'APPROVED',
        'proposed_arguments'    => json_encode(['x' => 1]),
        'approved_arguments'    => json_encode(['x' => 1]),
    ]);

    $orch->tick($task->id);

    $row = ToolCallModel::where('task_id', $task->id)
        ->where('provider_call_id', 'call_vfail_pickup')
        ->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('APPROVED')
        ->and($row->executed_at)->not->toBeNull()
        ->and($row->result_content)->toContain('Validation Error')
        ->and($row->result_content)->toContain('recipient');

    $toolHistory = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_vfail_pickup')
        ->first();
    expect($toolHistory)->not->toBeNull()
        ->and($toolHistory->content)->toContain('Validation Error');

    expect($llmCalls)->toBe(1);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('runTick() worker-mode pickup persists the failure result when safeExecute returns a failing ToolResult', function (): void {
    [$agentId] = seedAgent();

    $llmCalls = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$llmCalls) {
        $llmCalls++;
        return new LLMResponse('Done after tool failure.', [], 5, 3, 'cmp_sysfail');
    });

    // safeExecute converts a thrown exception into a failing ToolResult;
    // the persistence path here asserts the worker-mode pickup writes
    // that failure result_content + stamps executed_at.
    $tools = [new ThrowingTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);

    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'Throwing tool in pickup',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Throwing tool in pickup']);

    ToolCallModel::create([
        'task_id'               => $task->id,
        'agent_id'              => $agentId,
        'provider_call_id'      => 'call_throw_pickup',
        'tool_name'             => 'throwing_tool',
        'tool_class'            => ThrowingTool::class,
        'tool_type'             => 'output',
        'operation'             => 'default',
        'operation_description' => 'Pickup tool failure',
        'status'                => 'APPROVED',
        'proposed_arguments'    => json_encode([]),
        'approved_arguments'    => json_encode([]),
    ]);

    $orch->tick($task->id);

    $row = ToolCallModel::where('task_id', $task->id)
        ->where('provider_call_id', 'call_throw_pickup')
        ->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('APPROVED')
        ->and($row->executed_at)->not->toBeNull()
        ->and($row->result_content)->toContain('System Error')
        ->and($row->result_content)->toContain('Community plugin exploded');

    $toolHistory = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_throw_pickup')
        ->first();
    expect($toolHistory)->not->toBeNull()
        ->and($toolHistory->content)->toContain('System Error');

    expect($llmCalls)->toBe(1);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume() — worker-mode pickup via daemon (full e2e shape)
//
// Reviewer-facing description: in worker mode, the HTTP endpoint must
// return <50ms with status=QUEUED — the long-running tool is executed
// only when the worker daemon picks the task up and calls tick().
// ---------------------------------------------------------------------------

it('worker-mode resume returns immediately and defers tool execution to the next tick', function (): void {
    [$agentId] = seedAgent();

    // We use StubOutputTool (which is already registered) as the
    // "slow" tool. The test asserts the worker-mode pickup contract
    // via ToolCallModel.executed_at: NOT NULL after the daemon's tick.
    $llmSecond = Mockery::mock(LLMDriverInterface::class);
    $llmSecond->allows('complete')->andReturn(new LLMResponse('Done after worker pickup.', [], 5, 3, 'cmp_2'));

    // The LLM the resume HTTP path would use if it mistakenly fired —
    // configured with shouldNotReceive so any call here fails the test.
    $llmFirst = Mockery::mock(LLMDriverInterface::class);
    $llmFirst->shouldNotReceive('complete');

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);

    // Seed a PENDING_APPROVAL task with one PENDING_APPROVAL row so
    // we're testing the resume() / tick() pickup contract in
    // isolation. Driving an LLM round-trip to set up the pause would
    // require the worker-mode QUEUED→RUNNING dance, which is exercised
    // in WorkerModeTest.
    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'Worker pickup e2e',
        'step_count'  => 1,
        'max_steps'   => 10,
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Worker pickup e2e']);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => null]);

    $state = new AgentState(
        taskId: $task->id,
        agentId: $agentId,
        pendingToolCalls: [
            new DriverToolCall('call_slow', 'stub_output', ['x' => 1]),
        ],
        messageSnapshot: [],
        stepCount: 1,
        maxSteps: 10,
        pausedAt: date('Y-m-d\TH:i:s\Z'),
    );
    Task::where('id', $task->id)->update(['pending_state' => $state->toJson()]);

    ToolCallModel::create([
        'task_id'                  => $task->id,
        'agent_id'                 => $agentId,
        'provider_call_id'         => 'call_slow',
        'tool_name'                => 'stub_output',
        'tool_class'               => StubOutputTool::class,
        'tool_type'                => 'output',
        'operation'                => 'default',
        'operation_description'    => 'slow',
        'status'                   => 'PENDING_APPROVAL',
        'proposed_arguments'       => json_encode(['x' => 1]),
    ]);

    $orch = makeOrchestrator(
        mockDriverFactory($llmFirst),
        $tools,
    );

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');
    expect(ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_slow')->first()->executed_at)->toBeNull();

    // HTTP-equivalent path: resume() with one approval. Should record
    // the approval and return without touching the tool or LLM.
    $orch->resume($task->id, [['provider_call_id' => 'call_slow', 'decision' => 'approve', 'arguments' => ['x' => 99]]]);

    // Tool still has NOT been executed (no executed_at) — the HTTP
    // path only recorded the approval. llmFirst is wired with
    // shouldNotReceive('complete') so any LLM call would also fail.
    expect(ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_slow')->first()->executed_at)->toBeNull();

    $task->refresh();
    // All approved (only one tool call here) → pending_state cleared,
    // task marked QUEUED for the daemon to pick up. Compare against
    // the partial-approval test where pending_state stays populated.
    expect($task->status)->toBe('QUEUED')
        ->and($task->pending_state)->toBeNull();

    // Now simulate the worker daemon picking up the task. The
    // production worker (`bin/spora task:run <id>`) does a
    // QUEUED → RUNNING transition inside a lockForUpdate transaction
    // BEFORE calling tick(), since Orchestrator::tick() is a no-op
    // on QUEUED tasks (lockRunningTaskForTick only proceeds when the
    // task is already RUNNING). Mirror that here.
    Task::where('id', $task->id)->update(['status' => 'RUNNING']);

    // Now swap to a fresh orchestrator + an LLM that returns the final
    // answer on its one round-trip.
    $orch = makeOrchestrator(
        mockDriverFactory($llmSecond),
        $tools,
    );
    $orch->tick($task->id);

    $row = ToolCallModel::where('task_id', $task->id)->where('provider_call_id', 'call_slow')->first();
    expect($row->status)->toBe('APPROVED')
        ->and($row->executed_at)->not->toBeNull();

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Done after worker pickup.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume() — exception path: task is marked RESUME_FAILED
// ---------------------------------------------------------------------------

it('classifies a failure from the tick that follows a resume normally', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        // First LLM call: pause for approval.
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_x', 'stub_output', [])], 5, 3, 'cmp_1');
        }
        // Second LLM call (the tick that picks up the approved batch): blow up.
        throw new RuntimeException('LLM is down on the resumed tick.');
    });

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Resume exception test', maxSteps: 10);
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    $orch->resume($task->id, [['provider_call_id' => 'call_x', 'decision' => 'approve', 'arguments' => []]]);

    try {
        claimAndTick($orch, $task->id);
        PHPUnit\Framework\Assert::fail('Expected RuntimeException to propagate from the post-resume tick');
    } catch (RuntimeException $e) {
        // Expected
    }

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('UNKNOWN')
        ->and($task->error_message)->toBe('An unexpected error occurred. Please try again.');
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
    claimAndTick($orch, $task->id);

    $task->refresh();
    // Override forced auto-approval → no PENDING_APPROVAL, task completes immediately.
    expect($task->status)->toBe('COMPLETED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// scheduleAutoRetry — when retry policy is configured
// ---------------------------------------------------------------------------

it('scheduleAutoRetry schedules the retry in place when error code is retryable and retry policy is configured', function (): void {
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
        // scheduleAutoRetry records retry_after on the same task and exits
        // without a second LLM call.
        if ($callCount === 1) {
            throw new LLMRateLimitException('429 rate limit');
        }
        return new LLMResponse('Done.', [], 5, 3, 'cmp_retry');
    });

    $orch = makeOrchestrator(mockDriverFactory($mock));

    $task = Task::create([
        'agent_id'      => $agentId,
        'principal_id' => createUserPrincipalPublic($agent->user_id),
        'trigger_user_id' => $agent->user_id,
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

    // The retry is scheduled IN PLACE on the same task: retry_count is
    // incremented, retry_after points to the future, retry_of_task_id marks
    // it as part of a chain (self-reference), and status stays FAILED so the
    // UI countdown banner remains visible.
    expect($task->retry_count)->toBe(1)
        ->and($task->retry_after)->not->toBeNull()
        ->and($task->retry_of_task_id)->toBe($task->id);

    // No separate retry row is created — same task_id is re-ticked.
    $separateRetries = Task::where('retry_of_task_id', $task->id)
        ->where('id', '!=', $task->id)
        ->count();
    expect($separateRetries)->toBe(0);

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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
        'principal_id' => createUserPrincipalPublic($agent->user_id),
        'trigger_user_id' => $agent->user_id,
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
        public function execute(
            array $arguments,
            int $agentId,
            ?int $userId = null,
            ?int $taskId = null,
            ?Spora\Services\PrincipalContext $context = null,
        ): ToolResult {
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
    $task = $orch->start($agentId, 'Plain tool test', maxSteps: 5);
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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

it('resume rejects an empty decisions list when pending_state is null', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(
        new LLMResponse(null, [new DriverToolCall('call_rec', 'stub_output', [])], 5, 3, 'cmp_1'),
    );

    $tools = [new StubOutputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Null pending state test', maxSteps: 10);
    claimAndTick($orch, $task->id);

    Task::where('id', $task->id)->update(['pending_state' => null]);

    expect(fn() => $orch->resume($task->id, []))
        ->toThrow(InvalidArgumentException::class, 'decisions must be a non-empty array');

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// qualifiedToolName — plugin tool name prefix
// ---------------------------------------------------------------------------

it('qualifiedToolName prepends the plugin slug when the tool belongs to a registered plugin', function (): void {
    [$agentId] = seedAgent();

    // Define a tool that we will attribute to a plugin.
    $pluginTool = new #[Tool(name: 'plugin_search', description: 'Search via plugin')]
    class implements ToolInterface {
        public function execute(
            array $arguments,
            int $agentId,
            ?int $userId = null,
            ?int $taskId = null,
            ?Spora\Services\PrincipalContext $context = null,
        ): ToolResult {
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
        mockDriverFactory($mock),
        new OrchestratorConfig(
            toolInstances: [$pluginTool],
            pluginLoader: $pluginLoader,
        ),
    );
    $task = $orch->start($agentId, 'Plugin tool test', maxSteps: 5);
    claimAndTick($orch, $task->id);

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
    claimAndTick($orch, $task->id);

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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
    claimAndTick($orch, $task->id);

    // Should complete without throwing even though llmConfigService is null.
    expect($task->refresh()->status)->toBe('COMPLETED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// publishIntermediateState — uses ToolCallSerializer when not injected
// ---------------------------------------------------------------------------

it('publishIntermediateState falls back to a default ToolCallSerializer when none is injected', function (): void {
    [$agentId] = seedAgent();

    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
    $mockMercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mockMercure->allows('publishForPrincipal')->andReturn(true);

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
        mockDriverFactory($mock),
        new OrchestratorConfig(
            toolInstances: $tools,
            mercure: $mockMercure,
        ),
    );

    $task = $orch->start($agentId, 'Default serializer test', maxSteps: 10);
    claimAndTick($orch, $task->id);

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
        'principal_id' => (int) Agent::find($agentId)->principal_id,
        'trigger_user_id' => Agent::find($agentId)->user_id,
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
    $task = $orch->start($agentId, 'Default system prompt test', maxSteps: 5);
    claimAndTick($orch, $task->id);

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
    $task = $orch->start($agentId, 'Custom system prompt test', maxSteps: 5);
    claimAndTick($orch, $task->id);

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
        /** @var Mockery\MockInterface&\Spora\Services\MercurePublisherInterface $mockMercure */
        $mockMercure  = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mockMercure->allows('publishForPrincipal')->andReturnUsing(static function () use (&$publishCount): bool {
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
            mockDriverFactory($mock),
            new OrchestratorConfig(
                toolInstances: $tools,
                mercure: $mockMercure,
            ),
        );
        $task = $orch->start($agentId, 'Happy path test', maxSteps: 10);
        claimAndTick($orch, $task->id);

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
        claimAndTick($orch, $task->id);

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
        claimAndTick($orch, $task->id);

        $task->refresh();
        // The error is fed back to the LLM and the loop recovers.
        expect($task->status)->toBe('COMPLETED');

        // The error message is recorded in a tool history row. The wording
        // changed in the resume-auth fix to a clearer authorization message
        // (no misleading "System Error:" prefix) — see
        // TickPhaseRunner::handleToolCalls() catch block.
        $errorRow = TaskHistory::where('task_id', $task->id)
            ->where('role', 'tool')
            ->where('tool_call_id', 'call_unauth')
            ->first();
        expect($errorRow)->not()->toBeNull()
            ->and($errorRow->content)->toContain("Tool 'stub_input' is not enabled for this agent.")
            ->and($errorRow->content)->not->toContain('System Error');
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
        claimAndTick($orch, $task->id);

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
        claimAndTick($orch, $task->id);

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
        claimAndTick($orch, $task->id);

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
            'principal_id' => (int) Agent::find($agentId)->principal_id,
            'trigger_user_id' => Agent::find($agentId)->user_id,
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
            'principal_id' => (int) Agent::find($agentId)->principal_id,
            'trigger_user_id' => Agent::find($agentId)->user_id,
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
            'principal_id' => (int) Agent::find($agentId)->principal_id,
            'trigger_user_id' => Agent::find($agentId)->user_id,
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
            'principal_id' => (int) Agent::find($agentId)->principal_id,
            'trigger_user_id' => Agent::find($agentId)->user_id,
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
            'principal_id' => (int) Agent::find($agentId)->principal_id,
            'trigger_user_id' => Agent::find($agentId)->user_id,
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

//
// Regression test for the writer-side bug where appendHistory() called
// json_encode() on the attachment refs before assignment. The `attachments`
// column is already `cast => 'array'`, so the manual encode produced a
// double-encoded string. On hydration, the cast decoded the outer JSON
// and returned a STRING, not a list — leaving
// MessageHistoryBuilder::collectAttachmentBlocks unable to iterate, and
// silently dropping the file content. The LLM saw '[attachment]' instead.

describe('Orchestrator::start — attachment serialization round-trip', function (): void {
    function seedTextAsset(int $agentId, int $userId, string $body): MediaAsset
    {
        return MediaAsset::create([
            'id'                => sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff)),
            'asset_url'         => '/api/v1/assets/' . bin2hex(random_bytes(16)) . '.pdf',
            'storage_mode'      => 'data_url',
            'mime_type'         => 'application/pdf',
            'media_type'        => 'text',
            'byte_size'         => strlen($body),
            'agent_id'          => $agentId,
            'principal_id' => createUserPrincipalPublic($userId),
            'plugin_slug'       => null,
            'asset_token'       => bin2hex(random_bytes(16)),
            'public_access_token' => null,
            'filename'          => 'cv.pdf',
            'markdown_content'  => $body,
            'migrated_from_inline_data_url' => false,
        ]);
    }

    it('stores attachments as a JSON list (not a double-encoded string) so MessageHistoryBuilder emits the file content', function (): void {
        [$agentId, $userId] = seedAgent();

        $asset   = seedTextAsset($agentId, $userId, 'CV body — keep me in the loop');
        $assetId = $asset->id;

        $llm  = mockLlm(new LLMResponse('Done.', [], 10, 5, 'cmp_1'));
        $orch = makeOrchestrator(mockDriverFactory($llm));

        // start() goes through appendHistory with non-null attachments, which
        // is the exact code path that double-encoded the JSON before the fix.
        $task = $orch->start($agentId, 'Summarize the attached CV', maxSteps: 5, mediaIds: [$assetId]);

        // Refetching from a fresh query (not the in-memory model) is
        // important — it forces Eloquent to apply the `array` cast on
        // raw read.
        $attachmentRow = TaskHistory::where('task_id', $task->id)
            ->where('role', 'attachment')
            ->first();

        expect($attachmentRow)->not->toBeNull();

        // PHPStan can't prove the cast value is a list — reindex so
        // $refs[0] is statically typed.
        $refs = array_values((array) $attachmentRow->attachments);
        expect($refs)->toHaveCount(1)
            ->and($refs[0])->toMatchArray([
                'media_id' => $assetId,
                'kind'     => 'text',
            ]);

        // Before the fix this came back as the literal '[attachment]' fallback.
        $messages = (new Spora\Agents\MessageHistoryBuilder())->build($task->id);

        $userMessage = null;
        foreach ($messages as $msg) {
            if (($msg['role'] ?? null) === 'user' && is_array($msg['content'] ?? null)) {
                $userMessage = $msg;
                break;
            }
        }

        expect($userMessage)->not->toBeNull();
        $blocks = $userMessage['content'];
        $joinedText = implode("\n", array_map(static fn(array $b): string => (string) ($b['text'] ?? ''), $blocks));

        expect($joinedText)->toContain('CV body — keep me in the loop')
            ->and($joinedText)->not->toBe('[attachment]');
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});

// ---------------------------------------------------------------------------
// Orchestrator::retry — in-place re-run of a failed task
// ---------------------------------------------------------------------------

it('retry resets failed-task state in place (error fields cleared, history preserved, task re-ticked)', function (): void {
    Spora\Core\Database::resetBootState();
    $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();

    [$agentId, $userId] = seedAgent();

    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'       => $agentId,
        'status'         => 'FAILED',
        'user_prompt'    => 'retry me',
        'step_count'     => 5,
        'max_steps'      => 10,
        'error_code'     => 'SERVER_ERROR',
        'failure_reason' => 'previous failure',
    ]);
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'retry me']);

    // Mock the LLM to succeed — retry() re-queues the row and the next
    // tick transitions it FAILED → QUEUED → RUNNING → COMPLETED.
    $driver = mockLlm(new LLMResponse('Done.', [], 5, 3, 'cmp_retry'));
    $orch   = makeOrchestrator(mockDriverFactory($driver));

    $retried = $orch->retry($task->id);
    claimAndTick($orch, $retried->id);
    $retried->refresh();

    // Same task id, no new row created. The original 'user' row is preserved
    // as LLM context; the post-retry tick adds the assistant response row.
    expect($retried->id)->toBe($task->id)
        ->and((string) $retried->status)->toBe('COMPLETED')
        ->and((string) $retried->user_prompt)->toBe('retry me')
        ->and((int) $retried->max_steps)->toBe(10)
        ->and(TaskHistory::where('task_id', $task->id)->count())->toBe(2);

    // Original user message is still there — proof retry() did not rewrite
    // history, it just reset state and re-ticked.
    expect(TaskHistory::where('task_id', $task->id)->where('role', 'user')->count())->toBe(1);

    // Pre-retry error fields are gone (cleared by retry()), not leaking from
    // the failed state — the post-retry success path overwrites them too.
    expect($retried->error_code)->toBeNull()
        ->and($retried->failure_reason)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('retry resets the failed task in place and clears retry_of_task_id so the main QUEUED loop can claim it (Async mode)', function (): void {
    Spora\Core\Database::resetBootState();
    $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();

    [$agentId, $userId] = seedAgent();

    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'    => $agentId,
        'status'      => 'FAILED',
        'user_prompt' => 'retry me',
        'step_count'  => 5,
        'max_steps'   => 10,
        'error_code'  => 'SERVER_ERROR',
        'retry_count' => 1,
    ]);
    // Simulate the chain marker set by RetryScheduler::dispatchRetryTask.
    $task->retry_of_task_id = $task->id;
    $task->save();
    TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'retry me']);

    $driver = mockLlm(new LLMResponse('Done.', [], 5, 3, 'cmp_retry'));
    $orch   = makeOrchestrator(mockDriverFactory($driver));

    $retried = $orch->retry($task->id);

    // In Async (Worker) mode Orchestrator::retry() leaves the task QUEUED for
    // the worker to pick up. It MUST clear retry_of_task_id so the main
    // loop's claim predicate (`status='QUEUED' AND retry_of_task_id IS NULL`)
    // matches the row — otherwise it sits in QUEUED forever and the chain
    // never completes.
    expect((string) $retried->status)->toBe('QUEUED')
        ->and($retried->retry_of_task_id)->toBeNull()
        ->and($retried->retry_after)->toBeNull()
        ->and($retried->error_code)->toBeNull()
        ->and((int) $retried->step_count)->toBe(0)
        ->and((int) $retried->max_steps)->toBe(10)
        ->and((int) $retried->retry_count)->toBe(1); // preserved
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('retry throws when the task is not in FAILED status', function (): void {
    Spora\Core\Database::resetBootState();
    $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();

    [$agentId, $userId] = seedAgent();

    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'    => $agentId,
        'status'      => 'COMPLETED',
        'user_prompt' => 'already done',
        'max_steps'   => 10,
    ]);

    $orch = makeOrchestrator(mockDriverFactory(mockLlm(new LLMResponse('x', [], 1, 1, 'cmp'))));

    expect(fn() => $orch->retry($task->id))
        ->toThrow(InvalidTaskTransitionException::class, 'Can only retry failed tasks');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('singleStep=true breaks the post-tool-batch recursive tick so client-worker mode shows one LLM turn per call', function (): void {
    // Stub driver mirrors the "Run input tool" pattern: first turn returns
    // a tool_call, second turn returns the final text response. The
    // orchestrator's recursive-tick chain would normally consume both
    // turns inside a single Orchestrator::tick() call. With singleStep
    // (the client-worker /tick controller sets it), the chain is broken
    // after turn 1 so the SPA's per-tick rendering shows the tool call
    // landing before the final answer.
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [new DriverToolCall('call_1', 'stub_input', [])], 10, 5, 'cmp_1');
        }
        return new LLMResponse('Done via input tool.', [], 10, 5, 'cmp_2');
    });
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');
    $mock->allows('supportsImageInput')->andReturn(false);

    $tools = [new StubInputTool()];
    enableToolsForAgent($agentId, $tools);
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Run input tool', maxSteps: 10);

    // Manually claim the row to RUNNING so the recursive-tick gate at the
    // start of runTick() doesn't bail — mirrors TaskTickController's CAS
    // claim path.
    Task::where('id', $task->id)
        ->where('status', 'QUEUED')
        ->update(['status' => 'RUNNING']);

    $orch->tick($task->id, (new OrchestratorConfig())->withSingleStep(true));

    $task->refresh();
    // Turn 1 ran: tool_call persisted, status back to QUEUED, no final
    // answer. step_count = 1 because dispatchLlmTurn incremented it once.
    expect($task->status)->toBe('QUEUED')
        ->and($task->step_count)->toBe(1)
        ->and($task->final_response)->toBeNull();

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord)->not->toBeNull()
        ->and($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->result_content)->toBe('input_result');

    // Re-claim (the reaper would have cleared RUNNING to FAILED on lease
    // expiry — in practice the browser fires the next /tick fast enough
    // that the row stays QUEUED). Second tick: turn 2 returns text,
    // task completes.
    Task::where('id', $task->id)
        ->where('status', 'QUEUED')
        ->update(['status' => 'RUNNING']);
    $orch->tick($task->id, (new OrchestratorConfig())->withSingleStep(true));

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2)
        ->and($task->final_response)->toBe('Done via input tool.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
