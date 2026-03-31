<?php

declare(strict_types=1);

use Spora\Agents\Messages\TickMessage;
use Spora\Agents\Orchestrator;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Tests\Fixtures\StubAutoApproveOutputTool;
use Tests\Fixtures\StubInputTool;
use Tests\Fixtures\StubOutputTool;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a synchronous Messenger bus + Orchestrator pair.
 * Returns [$orchestrator, $bus].
 */
function makeOrchestrator(
    LLMDriverInterface $llmDriver,
    array $toolInstances = [],
): Orchestrator {
    $orchestratorRef = null;

    $bus = new MessageBus([
        new HandleMessageMiddleware(new HandlersLocator([
            TickMessage::class => [
                static function (TickMessage $msg) use (&$orchestratorRef): void {
                    $orchestratorRef->tick($msg->taskId);
                },
            ],
        ])),
    ]);

    $orchestratorRef = new Orchestrator(
        llmDriver: $llmDriver,
        bus: $bus,
        toolInstances: $toolInstances,
    );

    return $orchestratorRef;
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

// ---------------------------------------------------------------------------
// start() tests
// ---------------------------------------------------------------------------

it('start creates a RUNNING Task and seeds user history row', function (): void {
    [$agentId] = seedAgent();

    $llm = mockLlm(new LLMResponse(
        content: 'Done.',
        toolCall: null,
        inputTokens: 10,
        outputTokens: 5,
        completionId: 'cmp_1',
    ));

    $orch = makeOrchestrator($llm);
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

    $llm  = mockLlm(new LLMResponse('All done!', null, 10, 5, 'cmp_1'));
    $orch = makeOrchestrator($llm);

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

    $called = false;
    $mock   = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->never();

    $orch = makeOrchestrator($mock);

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
// tick() — InputTool path
// ---------------------------------------------------------------------------

it('InputTool path increments step_count and task stays RUNNING until LLM finishes', function (): void {
    [$agentId] = seedAgent();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            // First call: request a tool
            return new LLMResponse(null, new DriverToolCall('call_1', 'stub_input', []), 10, 5, 'cmp_1');
        }
        // Second call: done
        return new LLMResponse('Done via input tool.', null, 10, 5, 'cmp_2');
    });

    $orch = makeOrchestrator($mock, [new StubInputTool()]);
    $task = $orch->start($agentId, 'Run input tool', maxSteps: 10);

    $task->refresh();

    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(1)
        ->and($task->final_response)->toBe('Done via input tool.');

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->tool_type)->toBe('input')
        ->and($toolCallRecord->result_content)->toBe('input_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — OutputTool (requires approval) path
// ---------------------------------------------------------------------------

it('OutputTool requiring approval pauses task as PENDING_APPROVAL and serializes AgentState', function (): void {
    [$agentId] = seedAgent();

    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->once()->andReturn(
        new LLMResponse(null, new DriverToolCall('call_out', 'stub_output', ['key' => 'val']), 10, 5, 'cmp_1'),
    );

    $orch = makeOrchestrator($mock, [new StubOutputTool()]);
    $task = $orch->start($agentId, 'Run output tool', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL')
        ->and($task->pending_state)->not->toBeNull();

    $state = AgentState::fromJson($task->pending_state);
    expect($state->pendingToolCall->toolName)->toBe('stub_output')
        ->and($state->pendingToolCall->arguments)->toBe(['key' => 'val']);

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
            ? new LLMResponse(null, new DriverToolCall('call_auto', 'stub_auto_output', []), 5, 3, 'cmp_1')
            : new LLMResponse('Auto done.', null, 5, 3, 'cmp_2');
    });

    $orch = makeOrchestrator($mock, [new StubAutoApproveOutputTool()]);
    $task = $orch->start($agentId, 'Auto approve', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(1);

    $toolCallRecord = ToolCallModel::where('task_id', $task->id)->first();
    expect($toolCallRecord->status)->toBe('APPROVED')
        ->and($toolCallRecord->result_content)->toBe('auto_output_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// tick() — OutputTool auto-approved via AgentTool row override
// ---------------------------------------------------------------------------

it('AgentTool row auto_approve=1 overrides class-level requiresApproval=true', function (): void {
    [$agentId] = seedAgent();

    // Enable the tool with auto_approve=true (overrides class default of requiresApproval=true).
    AgentTool::create([
        'agent_id'     => $agentId,
        'tool_class'   => StubOutputTool::class,
        'tool_name'    => 'stub_output',
        'auto_approve' => 1,
    ]);

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? new LLMResponse(null, new DriverToolCall('call_ovr', 'stub_output', []), 5, 3, 'cmp_1')
            : new LLMResponse('Override done.', null, 5, 3, 'cmp_2');
    });

    $orch = makeOrchestrator($mock, [new StubOutputTool()]);
    $task = $orch->start($agentId, 'Override auto approve', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(1);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// max_steps
// ---------------------------------------------------------------------------

it('task is marked FAILED when step_count reaches max_steps', function (): void {
    [$agentId] = seedAgent();

    // LLM always requests a tool — never returns text.
    $callNum = 0;
    $mock    = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callNum) {
        $callNum++;

        return new LLMResponse(null, new DriverToolCall("call_{$callNum}", 'stub_input', []), 5, 3, "cmp_{$callNum}");
    });

    $orch = makeOrchestrator($mock, [new StubInputTool()]);
    $task = $orch->start($agentId, 'Infinite loop', maxSteps: 3);

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->failure_reason)->toBe('Max steps reached.')
        ->and($task->step_count)->toBe(3);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// resume()
// ---------------------------------------------------------------------------

it('resume executes the approved OutputTool, appends history, and re-dispatches tick', function (): void {
    [$agentId] = seedAgent();

    // First call: LLM requests output tool → pauses.
    // Second call (after resume): LLM returns text.
    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? new LLMResponse(null, new DriverToolCall('call_r', 'stub_output', ['x' => 1]), 5, 3, 'cmp_1')
            : new LLMResponse('Resumed.', null, 5, 3, 'cmp_2');
    });

    $orch = makeOrchestrator($mock, [new StubOutputTool()]);
    $task = $orch->start($agentId, 'Resume test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    $orch->resume($task->id, ['x' => 99]);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->step_count)->toBe(1)
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
    $orch = makeOrchestrator($mock);

    expect(fn() => $orch->resume($task->id, []))->toThrow(RuntimeException::class);
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
            ? new LLMResponse(null, new DriverToolCall('call_rej', 'stub_output', []), 5, 3, 'cmp_1')
            : new LLMResponse('Ok, rejected.', null, 5, 3, 'cmp_2');
    });

    $orch = makeOrchestrator($mock, [new StubOutputTool()]);
    $task = $orch->start($agentId, 'Reject test', maxSteps: 10);

    $task->refresh();
    expect($task->status)->toBe('PENDING_APPROVAL');

    $orch->reject($task->id, 'Too risky');

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Ok, rejected.');

    $rejectionRow = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->orderByDesc('sequence')
        ->skip(1)  // skip the final assistant row; find the injection
        ->first();

    // The rejection message should appear in a tool role row
    $allHistory = TaskHistory::where('task_id', $task->id)->where('role', 'tool')->get();
    $rejectionContent = $allHistory->first(fn($r) => str_contains((string) $r->content, 'Too risky'));
    expect($rejectionContent)->not->toBeNull();

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
    $orch = makeOrchestrator($mock);

    expect(fn() => $orch->reject($task->id, 'reason'))->toThrow(RuntimeException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());
