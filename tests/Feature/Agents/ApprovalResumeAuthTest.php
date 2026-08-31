<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Agents\ApprovedBatchExecutor;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\TickPhaseRunner;
use Spora\Agents\ToolCallExecutor;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Tools\ToolInterface;
use Tests\Fixtures\StubInputTool;
use Tests\Fixtures\StubOutputTool;
use Tests\Fixtures\StubOutputToolDisabledByDefaultOp;
use Tests\Fixtures\StubOutputToolWithSchema;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * Coverage for the authorization re-check that runs when an approved batch
 * is executed ({@see TickPhaseRunner::executeApprovedPendingToolsForTask}),
 * reached either through `ApprovedBatchExecutor::execute` + the following
 * tick or straight from the daemon's tick, plus the wording of the
 * normal-tick `ToolNotEnabledException` history message that the LLM sees
 * on its next round-trip.
 *
 * Each scenario seeds a real PENDING_APPROVAL row + AgentTool row, then
 * mutates the AgentTool row (or adds an `AgentToolOperationOverride`) to
 * simulate the admin action, then drives the resume path and asserts on
 * the row + history outcome.
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Register a user, attach a default global LLM driver config, and create an
 * agent with the supplied tools enabled. Returns [userId, agentId].
 *
 * @param list<ToolInterface> $toolInstances
 * @return array{0: int, 1: int}
 */
function resumeAuthSeedAgent(array $toolInstances): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('resume-auth-' . uniqid() . '@test.local', TEST_PASSWORD, 'ResumeAuth');

    $config = LLMDriverConfiguration::create([
        'principal_id'         => null,
        'name'                 => 'Resume Auth Default Config',
        'driver_class'         => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'             => json_encode(['api_key' => 'test']),
        'is_global'            => true,
        'is_default'           => true,
        'context_window'       => 128000,
        'max_tokens_output'    => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'         => createUserPrincipalPublic($userId),
        'name'                 => 'Resume Auth Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    foreach ($toolInstances as $instance) {
        $toolName = match (true) {
            $instance instanceof StubOutputTool                    => 'stub_output',
            $instance instanceof StubOutputToolDisabledByDefaultOp => 'stub_output_disabled_default',
            $instance instanceof StubOutputToolWithSchema          => 'stub_output_with_schema',
            $instance instanceof StubInputTool                     => 'stub_input',
            default                                                 => throw new InvalidArgumentException(
                'Unknown tool class: ' . get_class($instance),
            ),
        };
        AgentTool::insert([
            'agent_id'   => $agent->id,
            'tool_class' => get_class($instance),
            'tool_name'  => $toolName,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    return [$userId, $agent->id];
}

/**
 * Create a task in PENDING_APPROVAL with a `pending_state` JSON snapshot
 * containing the given pending tool calls plus matching PENDING_APPROVAL
 * `tool_calls` rows. Returns the task id.
 *
 * @param list<DriverToolCall> $pendingToolCalls
 * @param array<string, string> $toolClassByToolName  map of toolName → FQCN
 */
function resumeAuthSeedPendingTask(
    int $userId,
    int $agentId,
    array $pendingToolCalls,
    array $toolClassByToolName,
): int {
    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'      => 'PENDING_APPROVAL',
        'user_prompt' => 'resume auth test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    $state = new AgentState(
        taskId: $task->id,
        agentId: $agentId,
        pendingToolCalls: $pendingToolCalls,
        messageSnapshot: [],
        stepCount: 0,
        maxSteps: 10,
        pausedAt: date('Y-m-d\TH:i:s\Z'),
    );
    $task->pending_state = $state->toJson();
    $task->save();

    foreach ($pendingToolCalls as $tc) {
        if (!isset($toolClassByToolName[$tc->toolName])) {
            throw new InvalidArgumentException("No tool class registered for tool name '{$tc->toolName}'.");
        }
        ToolCallModel::create([
            'task_id'             => $task->id,
            'agent_id'            => $agentId,
            'provider_call_id'    => $tc->providerCallId,
            'tool_name'           => $tc->toolName,
            'tool_class'          => $toolClassByToolName[$tc->toolName],
            'tool_type'           => 'output',
            'operation'           => 'default',
            'operation_description' => 'Run the stub output',
            'status'              => 'PENDING_APPROVAL',
            'proposed_arguments'  => $tc->arguments,
            'human_description'   => 'Run the stub output',
        ]);
    }

    return $task->id;
}

/**
 * Bare Orchestrator + DriverFactory pair with a no-op LLM driver.
 * Tests that don't drive the LLM use this; tests that need a specific
 * LLM response build their own DriverFactory mock.
 *
 * `toolInstances` is forwarded to {@see OrchestratorConfig::toolInstances}
 * because {@see Orchestrator::resolveToolByName()} iterates that array to
 * find a tool by name — passing it here lets the resume paths actually
 * look up the seeded tools.
 *
 * @param list<ToolInterface> $toolInstances
 */
function makeResumeAuthOrchestrator(array $toolInstances = []): Orchestrator
{
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturn(new LLMResponse('Ok', [], 1, 1, 'cmp_x'));
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    return new Orchestrator($factory, new OrchestratorConfig(toolInstances: $toolInstances));
}

// ---------------------------------------------------------------------------
// 1. Approval path — tool revoked mid-approval is NOT executed
// ---------------------------------------------------------------------------

it('resume rejects a tool revoked from the agent and does not execute it', function (): void {
    [$userId, $agentId] = resumeAuthSeedAgent([new StubOutputTool()]);

    $pendingTc = new DriverToolCall('call_a', 'stub_output', []);
    $taskId = resumeAuthSeedPendingTask(
        $userId,
        $agentId,
        [$pendingTc],
        ['stub_output' => StubOutputTool::class],
    );

    // Admin action: remove StubOutputTool from the agent.
    AgentTool::where('agent_id', $agentId)
        ->where('tool_class', StubOutputTool::class)
        ->delete();

    $orch = makeResumeAuthOrchestrator([new StubOutputTool()]);
    $executor = new ApprovedBatchExecutor(
        orchestrator: $orch,
        logger: new NullLogger(),
    );

    $executor->execute($taskId, [
        ['provider_call_id' => 'call_a', 'arguments' => []],
    ]);
    claimAndTick($orch, $taskId);

    $row = ToolCallModel::where('task_id', $taskId)
        ->where('provider_call_id', 'call_a')
        ->first();

    expect($row->status)->toBe('REJECTED')
        ->and($row->reject_reason)->toContain("tool 'stub_output' was revoked")
        ->and($row->rejected_at)->not->toBeNull()
        ->and($row->rejected_by)->toBeNull()
        ->and($row->result_content)->toBeNull()
        ->and($row->executed_at)->toBeNull();

    $history = Spora\Models\TaskHistory::where('task_id', $taskId)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_a')
        ->first();

    expect($history)->not->toBeNull()
        ->and($history->content)->toContain('Action rejected')
        ->and($history->content)->toContain("tool 'stub_output' was revoked from this agent before approval was processed")
        // Must not look like the tool failed — it was revoked before it ran.
        ->and($history->content)->not->toContain('output_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// 2. Worker path — tool revoked mid-approval is NOT executed
// ---------------------------------------------------------------------------

it('worker resume rejects a tool revoked from the agent and does not execute it', function (): void {
    [$userId, $agentId] = resumeAuthSeedAgent([new StubOutputTool()]);

    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'worker resume auth test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    // Worker-mode sentinel state: approved, not yet executed.
    ToolCallModel::create([
        'task_id'             => $task->id,
        'agent_id'            => $agentId,
        'provider_call_id'    => 'call_b',
        'tool_name'           => 'stub_output',
        'tool_class'          => StubOutputTool::class,
        'tool_type'           => 'output',
        'operation'           => 'default',
        'operation_description' => 'Run the stub output',
        'status'              => 'APPROVED',
        'proposed_arguments'  => [],
        'approved_arguments'  => json_encode([], JSON_THROW_ON_ERROR),
        'human_description'   => 'Run the stub output',
    ]);

    // Admin action: remove StubOutputTool from the agent before the daemon ticks.
    AgentTool::where('agent_id', $agentId)
        ->where('tool_class', StubOutputTool::class)
        ->delete();

    // DriverFactory mock — assigned to a variable so PHPStan sees it as
    // DriverFactory, not as Mockery\LegacyMockInterface. The runner's
    // `runTick()` (which would use it) isn't called here; we drive
    // `executeApprovedPendingToolsForTask` directly via reflection.
    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturnUsing(
        static fn(): LLMDriverInterface => throw new RuntimeException('LLM driver should not be invoked in this test.'),
    );

    $runner = new TickPhaseRunner(
        orchestrator: makeResumeAuthOrchestrator([new StubOutputTool()]),
        driverFactory: $driverFactory,
        toolInstances: [new StubOutputTool()],
        logger: new NullLogger(),
    );

    $ref = new ReflectionMethod($runner, 'executeApprovedPendingToolsForTask');
    $ref->invoke($runner, $task);

    $row = ToolCallModel::where('task_id', $task->id)
        ->where('provider_call_id', 'call_b')
        ->first();

    expect($row->status)->toBe('REJECTED')
        ->and($row->reject_reason)->toContain("tool 'stub_output' was revoked")
        ->and($row->rejected_at)->not->toBeNull()
        ->and($row->rejected_by)->toBeNull()
        ->and($row->executed_at)->toBeNull();

    $history = Spora\Models\TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_b')
        ->first();

    expect($history)->not->toBeNull()
        ->and($history->content)->toContain('Action rejected')
        ->and($history->content)->toContain("tool 'stub_output' was revoked from this agent before approval was processed")
        ->and($history->content)->not->toContain('output_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// 3. Operation-level revocation on resume
// ---------------------------------------------------------------------------

it('resume rejects when a specific operation is disabled before approval is processed', function (): void {
    [$userId, $agentId] = resumeAuthSeedAgent([new StubOutputTool()]);

    $pendingTc = new DriverToolCall('call_c', 'stub_output', []);
    $taskId = resumeAuthSeedPendingTask(
        $userId,
        $agentId,
        [$pendingTc],
        ['stub_output' => StubOutputTool::class],
    );

    // Admin disables the 'default' op for this tool/agent. Tool itself
    // stays enabled — only the specific op is off.
    AgentToolOperationOverride::create([
        'agent_id'                  => $agentId,
        'tool_class'                => StubOutputTool::class,
        'operation'                 => 'default',
        'enabled'                   => 0,
        'default_requires_approval' => null,
    ]);

    $orch = makeResumeAuthOrchestrator([new StubOutputTool()]);
    $executor = new ApprovedBatchExecutor(
        orchestrator: $orch,
        logger: new NullLogger(),
    );

    $executor->execute($taskId, [
        ['provider_call_id' => 'call_c', 'arguments' => []],
    ]);
    claimAndTick($orch, $taskId);

    $row = ToolCallModel::where('task_id', $taskId)
        ->where('provider_call_id', 'call_c')
        ->first();

    expect($row->status)->toBe('REJECTED')
        ->and($row->reject_reason)->toContain("operation 'default' of tool 'stub_output' was disabled")
        ->and($row->result_content)->toBeNull();

    $history = Spora\Models\TaskHistory::where('task_id', $taskId)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_c')
        ->first();
    expect($history)->not->toBeNull()
        ->and($history->content)->toContain("operation 'default' of tool 'stub_output' was disabled");
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// 4. Regression — tool still enabled, resume runs the tool
// ---------------------------------------------------------------------------

it('resume executes the tool when it is still enabled (regression for accidental always-reject)', function (): void {
    [$userId, $agentId] = resumeAuthSeedAgent([new StubOutputTool()]);

    $pendingTc = new DriverToolCall('call_d', 'stub_output', []);
    $taskId = resumeAuthSeedPendingTask(
        $userId,
        $agentId,
        [$pendingTc],
        ['stub_output' => StubOutputTool::class],
    );

    $orch = makeResumeAuthOrchestrator([new StubOutputTool()]);
    $executor = new ApprovedBatchExecutor(
        orchestrator: $orch,
        logger: new NullLogger(),
    );

    $executor->execute($taskId, [
        ['provider_call_id' => 'call_d', 'arguments' => []],
    ]);
    claimAndTick($orch, $taskId);

    $row = ToolCallModel::where('task_id', $taskId)
        ->where('provider_call_id', 'call_d')
        ->first();

    expect($row->status)->toBe('APPROVED')
        ->and($row->result_content)->toBe('output_result')
        ->and($row->executed_at)->not->toBeNull()
        ->and($row->reject_reason)->toBeNull();

    $history = Spora\Models\TaskHistory::where('task_id', $taskId)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_d')
        ->first();
    expect($history)->not->toBeNull()
        ->and($history->content)->toBe('output_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// 5. Boundary — tool revoked then re-enabled before approval succeeds
// ---------------------------------------------------------------------------

it('resume succeeds when the tool is re-enabled before the approval batch lands', function (): void {
    [$userId, $agentId] = resumeAuthSeedAgent([new StubOutputTool()]);

    $pendingTc = new DriverToolCall('call_e', 'stub_output', []);
    $taskId = resumeAuthSeedPendingTask(
        $userId,
        $agentId,
        [$pendingTc],
        ['stub_output' => StubOutputTool::class],
    );

    // Admin toggles: remove, then re-add before the user clicks approve.
    AgentTool::where('agent_id', $agentId)->where('tool_class', StubOutputTool::class)->delete();
    AgentTool::insert([
        'agent_id'   => $agentId,
        'tool_class' => StubOutputTool::class,
        'tool_name'  => 'stub_output',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $orch = makeResumeAuthOrchestrator([new StubOutputTool()]);
    $executor = new ApprovedBatchExecutor(
        orchestrator: $orch,
        logger: new NullLogger(),
    );

    $executor->execute($taskId, [
        ['provider_call_id' => 'call_e', 'arguments' => []],
    ]);
    claimAndTick($orch, $taskId);

    $row = ToolCallModel::where('task_id', $taskId)
        ->where('provider_call_id', 'call_e')
        ->first();

    // Latest snapshot wins — no "snapshot at proposal time" semantics.
    expect($row->status)->toBe('APPROVED')
        ->and($row->result_content)->toBe('output_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// 6. Partial-failure — revoked tool does not block other approved tools
// ---------------------------------------------------------------------------

it('resume rejects the revoked tool but still executes the other approved tools in the same batch', function (): void {
    [$userId, $agentId] = resumeAuthSeedAgent([new StubOutputTool(), new StubOutputToolWithSchema()]);

    $tcRevoked = new DriverToolCall('call_f1', 'stub_output', []);
    $tcKept    = new DriverToolCall('call_f2', 'stub_output_with_schema', ['recipient' => 'alice@example.com']);

    $taskId = resumeAuthSeedPendingTask(
        $userId,
        $agentId,
        [$tcRevoked, $tcKept],
        [
            'stub_output'             => StubOutputTool::class,
            'stub_output_with_schema' => StubOutputToolWithSchema::class,
        ],
    );

    // Admin revokes ONLY stub_output.
    AgentTool::where('agent_id', $agentId)->where('tool_class', StubOutputTool::class)->delete();

    $orch = makeResumeAuthOrchestrator([new StubOutputTool(), new StubOutputToolWithSchema()]);
    $executor = new ApprovedBatchExecutor(
        orchestrator: $orch,
        logger: new NullLogger(),
    );

    $executor->execute($taskId, [
        ['provider_call_id' => 'call_f1', 'arguments' => []],
        ['provider_call_id' => 'call_f2', 'arguments' => ['recipient' => 'alice@example.com']],
    ]);
    claimAndTick($orch, $taskId);

    $revokedRow = ToolCallModel::where('task_id', $taskId)->where('provider_call_id', 'call_f1')->first();
    $keptRow    = ToolCallModel::where('task_id', $taskId)->where('provider_call_id', 'call_f2')->first();

    expect($revokedRow->status)->toBe('REJECTED')
        ->and($revokedRow->result_content)->toBeNull();

    expect($keptRow->status)->toBe('APPROVED')
        ->and($keptRow->result_content)->toBe('output_with_schema_result');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// 7. Normal tick — ToolNotEnabledException surfaces a clear LLM-facing message
// ---------------------------------------------------------------------------

it('normal tick informs the LLM with a clear message when it proposes a non-enabled tool', function (): void {
    $authService = bootAuthLayer();
    $userId      = $authService->register('normal-tick-llm@example.com', TEST_PASSWORD, 'NormalTickLlm');

    $config = LLMDriverConfiguration::create([
        'principal_id'         => null,
        'name'                 => 'Normal Tick LLM Test Config',
        'driver_class'         => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'             => json_encode(['api_key' => 'test']),
        'is_global'            => true,
        'is_default'           => true,
        'context_window'       => 128000,
        'max_tokens_output'    => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'         => $this->createUserPrincipal($userId),
        'name'                 => 'Normal Tick LLM Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    // Agent has NO tools enabled — the LLM's stub_input proposal is unauthorized.
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturn(
        new LLMResponse(
            null,
            [new DriverToolCall('call_unauth', 'stub_input', [])],
            5,
            3,
            'cmp_1',
        ),
    );
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    $orch = new Orchestrator(
        $factory,
        new OrchestratorConfig(toolInstances: [new StubInputTool()]),
    );

    $task = $orch->start($agent->id, 'Tool not enabled test', maxSteps: 3);
    claimAndTick($orch, $task->id);

    $history = Spora\Models\TaskHistory::where('task_id', $task->id)
        ->where('role', 'tool')
        ->where('tool_call_id', 'call_unauth')
        ->first();

    // New wording: a clear, authorization-specific message — NOT a generic
    // "System Error:" prefix that the LLM would mistake for a tool failure.
    expect($history)->not->toBeNull()
        ->and($history->content)->toContain("Tool 'stub_input' is not enabled for this agent.")
        ->and($history->content)->toContain('do not propose it again')
        ->and($history->content)->not->toContain('System Error');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// 8. Normal tick — gate re-loads enabledClasses so mid-tick revocation is honoured
// ---------------------------------------------------------------------------
//
// Regression: the original gate at ToolCallExecutor::executeOrQueue() used a
// snapshot of enabledClasses captured at tick start (TickPhaseRunner::prepareTickContext
// loads it once, before the LLM round-trip). If an admin revoked the tool
// mid-round-trip, the gate was stale and let the revoked tool run. The fix
// re-loads enabledClasses inside executeOrQueue() so the check sees the
// current DB state, not any cached snapshot.

it('executeOrQueue re-loads enabledClasses so a mid-tick revocation is honoured', function (): void {
    [$userId, $agentId] = resumeAuthSeedAgent([new StubInputTool()]);

    $task = Task::create([
        'agent_id'    => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'stale snapshot test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    // Confirm the allow-list currently contains the tool — gate baseline.
    expect(AgentTool::where('agent_id', $agentId)->pluck('tool_class')->all())
        ->toBe([StubInputTool::class]);

    // Mid-tick admin action: revoke StubInputTool from the agent.
    AgentTool::where('agent_id', $agentId)
        ->where('tool_class', StubInputTool::class)
        ->delete();

    // The LLM is still proposing stub_input (it was in the list sent to the
    // LLM earlier in the tick) — the gate must still fire because the
    // DB state has changed.
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    $orch     = new Orchestrator($factory, new OrchestratorConfig(toolInstances: [new StubInputTool()]));
    $executor = new ToolCallExecutor($orch);
    $agent    = Agent::find($agentId);

    $toolCall = new DriverToolCall('call_stale', 'stub_input', []);

    // The gate re-loads enabledClasses from the DB inside executeOrQueue(),
    // so the deleted row is reflected and the check fires. Without the
    // re-load (the old snapshot-based behaviour), the call would have
    // passed the gate.
    expect(fn() => $executor->executeOrQueue($toolCall, $agent, $task))
        ->toThrow(Spora\Agents\Exceptions\ToolNotEnabledException::class, 'not enabled for this agent');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
