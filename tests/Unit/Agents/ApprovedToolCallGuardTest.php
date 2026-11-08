<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Tests\Fixtures\StubTwoOperationTool;

/**
 * The approval authorizes an operation; the arguments the approver supplies
 * select the operation that actually runs. A replacement that names another
 * operation would run under the first operation's enablement decision.
 */
function approvalGuardSeed(): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('approval-guard@example.com', 'Password1!', 'Approval Guard');

    $config = LLMDriverConfiguration::create([
        'principal_id'       => null,
        'name'               => 'Approval Guard Config',
        'driver_class'       => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'           => json_encode(['api_key' => 'test']),
        'is_global'          => true,
        'is_default'         => true,
        'context_window'     => 128000,
        'max_tokens_output'  => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'       => createUserPrincipalPublic($userId),
        'name'               => 'Approval Guard Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'          => 10,
        'is_active'          => true,
    ]);

    AgentTool::insert([
        'agent_id'   => $agent->id,
        'tool_class' => StubTwoOperationTool::class,
        'tool_name'  => 'stub_two_operation',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'run the safe operation',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    return [$agent->id, $task->id, $userId];
}

/**
 * An approved row whose stored operation is `safe`, with the approver's
 * replacement arguments naming `dangerous`.
 */
function approvalGuardApprovedRow(int $taskId, int $agentId, string $operation, array $approvedArgs): void
{
    ToolCallModel::create([
        'task_id'            => $taskId,
        'agent_id'           => $agentId,
        'provider_call_id'   => 'call_approval_guard',
        'tool_name'          => 'stub_two_operation',
        'tool_class'         => StubTwoOperationTool::class,
        'tool_type'          => 'output',
        'operation'          => $operation,
        'status'             => 'APPROVED',
        'proposed_arguments' => ['action' => $operation],
        'approved_arguments' => $approvedArgs,
        'executed_at'        => null,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);
}

function approvalGuardOrchestrator(StubTwoOperationTool $tool): Orchestrator
{
    $driver = Mockery::mock(Spora\Drivers\LLMDriverInterface::class);
    $driver->allows('complete')->andReturn(new Spora\Drivers\ValueObjects\LLMResponse('Done.', [], 10, 5, 'cmp_1'));
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $driver->allows('supportsImageInput')->andReturn(false);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    return new Orchestrator($factory, new OrchestratorConfig(toolInstances: [$tool]));
}

it('refuses an approval that swaps the operation for a disabled one', function (): void {
    [$agentId, $taskId] = approvalGuardSeed();

    approvalGuardApprovedRow($taskId, $agentId, 'safe', ['action' => 'dangerous']);

    $tool = new StubTwoOperationTool();
    $orchestrator = approvalGuardOrchestrator($tool);

    $orchestrator->tick($taskId);

    expect($tool->dispatchedOperation)->toBeNull();

    $row = ToolCallModel::where('task_id', $taskId)->first();
    expect($row->status)->toBe('REJECTED')
        ->and($row->reject_reason)->toContain('changed operation');
});

it('runs an approval that keeps the operation it authorized', function (): void {
    [$agentId, $taskId] = approvalGuardSeed();

    approvalGuardApprovedRow($taskId, $agentId, 'safe', ['action' => 'safe']);

    $tool = new StubTwoOperationTool();
    $orchestrator = approvalGuardOrchestrator($tool);

    $orchestrator->tick($taskId);

    expect($tool->dispatchedOperation)->toBe('safe');

    $row = ToolCallModel::where('task_id', $taskId)->first();
    expect($row->status)->toBe('APPROVED')
        ->and($row->executed_at)->not->toBeNull();
});

it('refuses a second proposal that reuses a provider call id', function (): void {
    [$agentId, $taskId] = approvalGuardSeed();

    $tool = new StubTwoOperationTool();
    $orchestrator = approvalGuardOrchestrator($tool);

    $executor = $orchestrator->toolCallExecutor;
    $agent    = Agent::find($agentId);
    $task     = Task::find($taskId);

    $first = new DriverToolCall(
        'call_duplicate',
        'stub_two_operation',
        ['action' => 'safe'],
    );

    // The first proposal is recorded.
    $executor->executeOrQueue($first, $agent, $task);

    $second = new DriverToolCall(
        'call_duplicate',
        'stub_two_operation',
        ['action' => 'safe'],
    );

    $disposition = $executor->executeOrQueue($second, $agent, $task);

    expect($disposition)->toBe(Spora\Agents\ToolCallDisposition::DuplicateCallId)
        ->and(ToolCallModel::where('task_id', $taskId)->count())->toBe(1);
});

it('allows a call id to be reused once the earlier call has finished', function (): void {
    [$agentId, $taskId] = approvalGuardSeed();

    // A backend may reuse a call id in a later turn. The earlier call has
    // run, so nothing is awaiting a decision under that id and the reuse
    // is not ambiguous.
    ToolCallModel::create([
        'task_id'            => $taskId,
        'agent_id'           => $agentId,
        'provider_call_id'   => 'call_reused',
        'tool_name'          => 'stub_two_operation',
        'tool_class'         => StubTwoOperationTool::class,
        'tool_type'          => 'output',
        'operation'          => 'safe',
        'status'             => 'APPROVED',
        'proposed_arguments' => ['action' => 'safe'],
        'executed_at'        => date('Y-m-d H:i:s'),
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    $tool = new StubTwoOperationTool();
    $orchestrator = approvalGuardOrchestrator($tool);

    $disposition = $orchestrator->toolCallExecutor->executeOrQueue(
        new DriverToolCall('call_reused', 'stub_two_operation', ['action' => 'safe']),
        Agent::find($agentId),
        Task::find($taskId),
    );

    expect($disposition)->not->toBe(Spora\Agents\ToolCallDisposition::DuplicateCallId)
        ->and(ToolCallModel::where('task_id', $taskId)->count())->toBe(2);
});
