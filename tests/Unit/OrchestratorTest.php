<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Tests\Fixtures\SpyAgentIdInputTool;
use Tests\Fixtures\StubAutoApproveOutputTool;
use Tests\Fixtures\StubFailingTool;
use Tests\Fixtures\StubInputTool;
use Tests\Fixtures\StubOutputTool;
use Tests\Fixtures\StubOutputToolWithSchema;
use Tests\Fixtures\ThrowingTool;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeOrchestrator(
    DriverFactory $driverFactory,
    array $toolInstances = [],
    ?Psr\Log\LoggerInterface $logger = null,
): Orchestrator {
    return new Orchestrator(
        driverFactory: $driverFactory,
        llmConfigService: null,
        toolInstances: $toolInstances,
        logger: $logger,
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
    $userId      = $authService->register('orch@example.com', 'Password1!');

    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Test Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock-model',
        'max_steps'    => 10,
        'is_active'    => true,
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
    $task = $orch->start($agentId, 'Hello!', maxSteps: 5);

    expect($task->status)->toBe('COMPLETED')      // tick ran synchronously
        ->and($task->user_prompt)->toBe('Hello!')
        ->and($task->max_steps)->toBe(5);

    $history = TaskHistory::where('task_id', $task->id)->orderBy('sequence')->get();
    expect($history->first()->role)->toBe('user')
        ->and($history->first()->content)->toBe('Hello!');
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

// ---------------------------------------------------------------------------
// tick() — Operation auto-approved via AgentToolOperationOverride
// ---------------------------------------------------------------------------

it('AgentToolOperationOverride.default_requires_approval=0 overrides requiresApprovalByDefault=true', function (): void {
    [$agentId] = seedAgent();

    AgentTool::create([
        'agent_id'     => $agentId,
        'tool_class'   => StubOutputTool::class,
        'tool_name'    => 'stub_output',
    ]);

    // Override the operation to auto-approve instead of requiring approval.
    AgentToolOperationOverride::create([
        'agent_id'                  => $agentId,
        'tool_class'                => StubOutputTool::class,
        'operation'                 => 'default',
        'default_requires_approval' => 0, // false
    ]);

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? new LLMResponse(null, [new DriverToolCall('call_ovr', 'stub_output', [])], 5, 3, 'cmp_1')
            : new LLMResponse('Override done.', [], 5, 3, 'cmp_2');
    });

    $tools = [new StubOutputTool()];
    $orch = makeOrchestrator(mockDriverFactory($mock), $tools);
    $task = $orch->start($agentId, 'Override auto approve', maxSteps: 10);

    $task->refresh();
    // 2 LLM turns: one for the tool, one for the final text.
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(2);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
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

    expect(fn() => $orch->resume($task->id, []))->toThrow(RuntimeException::class);
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
        ->and($toolCall->result_content)->toContain('Validation Error');
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

    expect(fn() => $orch->reject($task->id, 'reason'))->toThrow(RuntimeException::class);
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
        ->and($toolCallRecord->result_content)->toContain('Validation Error');

    // A history row for this tool call must also exist (atomically written with the record above)
    $toolHistory = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_schema_fail')
        ->first();
    expect($toolHistory)->not()->toBeNull()
        ->and($toolHistory->content)->toContain('Validation Error');
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
    $capturedMessages = null;

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
    $capturedMessages = null;

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

    $capturedMessages = null;

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
