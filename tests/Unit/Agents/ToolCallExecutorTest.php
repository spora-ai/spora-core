<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ToolCallDisposition;
use Spora\Agents\ToolCallExecutor;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Traits\HasOperations;
use Tests\Fixtures\StubInputTool;
use Tests\Fixtures\StubOutputTool;
use Tests\Fixtures\StubOutputToolWithSchema;
use Tests\Fixtures\ThrowingTool;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * Create an Orchestrator that hosts a ToolCallExecutor. The DriverFactory is not
 * exercised by these tests because they invoke the executor directly.
 */
function makeExecutorHost(array $toolInstances = []): Orchestrator
{
    $factory = Mockery::mock(DriverFactory::class);

    return new Orchestrator(
        $factory,
        new OrchestratorConfig(toolInstances: $toolInstances),
    );
}

/**
 * Build an agent + task pair ready for executor tests.
 *
 * @return array{0: int, 1: Task}
 */
function seedAgentAndTask(array $toolInstances = []): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('exec@example.com', TEST_PASSWORD, 'Exec');

    $config = LLMDriverConfiguration::create([
        'user_id'           => null,
        'name'              => 'Executor Test Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Executor Test Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    foreach ($toolInstances as $instance) {
        AgentTool::insert([
            'agent_id'   => $agent->id,
            'tool_class' => get_class($instance),
            'tool_name'  => 'test_tool',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'executor test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    return [$agent->id, $task];
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('executor returns Executed for an auto-approved input tool and records the result', function (): void {
    [$agentId, $task] = seedAgentAndTask([new StubInputTool()]);
    $agent = Agent::find($agentId);

    $orch = makeExecutorHost([new StubInputTool()]);
    $executor = new ToolCallExecutor($orch);

    $toolCall = new DriverToolCall('call_exec', 'stub_input', []);
    $disposition = $executor->executeOrQueue($toolCall, $agent, $task, [StubInputTool::class]);

    expect($disposition)->toBe(ToolCallDisposition::Executed);

    $record = ToolCallModel::where('task_id', $task->id)->first();
    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('APPROVED')
        ->and($record->tool_type)->toBe('input')
        ->and($record->result_content)->toBe('input_result');

    $history = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_exec')
        ->first();
    expect($history)->not->toBeNull()
        ->and($history->content)->toBe('input_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Awaiting approval
// ---------------------------------------------------------------------------

it('executor returns AwaitingApproval for an output tool and leaves the record PENDING_APPROVAL', function (): void {
    [$agentId, $task] = seedAgentAndTask([new StubOutputTool()]);
    $agent = Agent::find($agentId);

    $orch = makeExecutorHost([new StubOutputTool()]);
    $executor = new ToolCallExecutor($orch);

    $toolCall = new DriverToolCall('call_out', 'stub_output', ['key' => 'val']);
    $disposition = $executor->executeOrQueue($toolCall, $agent, $task, [StubOutputTool::class]);

    expect($disposition)->toBe(ToolCallDisposition::AwaitingApproval);

    $record = ToolCallModel::where('task_id', $task->id)->first();
    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('PENDING_APPROVAL')
        ->and($record->tool_type)->toBe('output')
        ->and($record->result_content)->toBeNull()
        ->and($record->executed_at)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Validation failure
// ---------------------------------------------------------------------------

it('executor returns ValidationFailed and records the validation error atomically', function (): void {
    [$agentId, $task] = seedAgentAndTask([new StubOutputToolWithSchema()]);
    $agent = Agent::find($agentId);

    $orch = makeExecutorHost([new StubOutputToolWithSchema()]);
    $executor = new ToolCallExecutor($orch);

    // Missing required 'recipient' field triggers SchemaValidator.
    $toolCall = new DriverToolCall('call_val_fail', 'stub_output_with_schema', []);
    $disposition = $executor->executeOrQueue($toolCall, $agent, $task, [StubOutputToolWithSchema::class]);

    expect($disposition)->toBe(ToolCallDisposition::ValidationFailed);

    $record = ToolCallModel::where('task_id', $task->id)->first();
    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('APPROVED')
        ->and($record->result_content)->toContain('Validation Error');

    $history = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_val_fail')
        ->first();
    expect($history)->not->toBeNull()
        ->and($history->content)->toContain('Validation Error');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Disabled operation (HasOperations)
// ---------------------------------------------------------------------------

it('executor returns OperationDisabled and writes a DISABLED record when the operation is disabled for the agent', function (): void {
    [$agentId, $task] = seedAgentAndTask([new StubInputTool()]);
    $agent = Agent::find($agentId);

    AgentToolOperationOverride::create([
        'agent_id'                  => $agentId,
        'tool_class'                => StubInputTool::class,
        'operation'                 => 'default',
        'enabled'                   => 0,
        'default_requires_approval' => null,
    ]);

    $orch = makeExecutorHost([new StubInputTool()]);
    $executor = new ToolCallExecutor($orch);

    $toolCall = new DriverToolCall('call_disabled', 'stub_input', ['action' => 'default']);
    $disposition = $executor->executeOrQueue($toolCall, $agent, $task, [StubInputTool::class]);

    expect($disposition)->toBe(ToolCallDisposition::OperationDisabled);

    $record = ToolCallModel::where('task_id', $task->id)->first();
    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('DISABLED')
        ->and($record->tool_type)->toBe('operation');

    $history = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_disabled')
        ->first();
    expect($history)->not->toBeNull()
        ->and($history->content)->toContain('disabled');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// safeExecute catches throwables
// ---------------------------------------------------------------------------

it('executor still returns Executed when the tool throws — safeExecute converts it to a failed ToolResult', function (): void {
    [$agentId, $task] = seedAgentAndTask([new ThrowingTool()]);
    $agent = Agent::find($agentId);

    $orch = makeExecutorHost([new ThrowingTool()]);
    $executor = new ToolCallExecutor($orch);

    $toolCall = new DriverToolCall('call_throw', 'throwing_tool', []);
    $disposition = $executor->executeOrQueue($toolCall, $agent, $task, [ThrowingTool::class]);

    expect($disposition)->toBe(ToolCallDisposition::Executed);

    $record = ToolCallModel::where('task_id', $task->id)->first();
    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('APPROVED')
        ->and($record->result_content)->toContain('System Error')
        ->and($record->result_content)->toContain('Community plugin exploded');

    $history = TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_throw')
        ->first();
    expect($history)->not->toBeNull()
        ->and($history->content)->toContain('System Error');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Disabled tool (not enabled for the agent) — executor throws
// ---------------------------------------------------------------------------

it('executor throws when the LLM invokes a tool that is not enabled for the agent', function (): void {
    [$agentId, $task] = seedAgentAndTask(); // no tools enabled
    $agent = Agent::find($agentId);

    $orch = makeExecutorHost([new StubInputTool()]);
    $executor = new ToolCallExecutor($orch);

    $toolCall = new DriverToolCall('call_unauth', 'stub_input', []);

    expect(fn() => $executor->executeOrQueue($toolCall, $agent, $task, []))
        ->toThrow(RuntimeException::class, 'not enabled');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
