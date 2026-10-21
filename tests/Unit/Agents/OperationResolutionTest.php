<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ToolCallDisposition;
use Spora\Agents\ToolCallExecutor;
use Spora\Agents\ToolOperationResolver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SubAgentTool;

/**
 * The operation a tool call dispatches to has to agree with the operation
 * the LLM-facing schema offered.
 *
 * `OperationSchemaFilter` drops the `op` discriminator from `required[]`
 * when the agent has exactly one operation enabled — on the assumption that
 * the single allowed op is then unambiguous. The runtime fallback in
 * `HasOperations::getOperationName()` is the FIRST DECLARED operation, not
 * the only ENABLED one, so an agent with only `handover` enabled used to
 * route an op-less call to `sub_agent` and answer "Operation 'sub_agent' is
 * disabled for this agent."
 */
const OP_RESOLUTION_TARGET_AGENT_ID = 5;

/**
 * Build an Orchestrator that hosts the SubAgentTool.
 *
 * @param list<object> $toolInstances
 */
function opResolutionOrchestrator(array $toolInstances): Orchestrator
{
    return new Orchestrator(
        Mockery::mock(DriverFactory::class),
        new OrchestratorConfig(toolInstances: $toolInstances),
    );
}

/**
 * Seed an agent that owns the SubAgentTool, plus its task.
 *
 * @return array{agentId: int, task: Task}
 */
function opResolutionSeedAgent(): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('op-resolution@example.com', 'Password1!', 'OpResolution');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'              => 'Op Resolution Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'                 => 'Op Resolution Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    AgentTool::insert([
        'agent_id'   => $agent->id,
        'tool_class' => SubAgentTool::class,
        'tool_name'  => 'sub_agent',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // The target agent the LLM names. `SubAgentTool::isTargetAllowed()`
    // cross-checks the principal, so it has to exist and share one.
    Illuminate\Database\Capsule\Manager::table('agents')->insert([
        'id'                 => OP_RESOLUTION_TARGET_AGENT_ID,
        'principal_id'       => createUserPrincipalPublic($userId),
        'name'               => 'Op Resolution Target',
        'llm_driver_config_id' => $config->id,
        'max_steps'          => 5,
        'is_active'          => 1,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'op resolution',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    return ['agentId' => $agent->id, 'task' => $task];
}

/**
 * A SubAgentTool whose collaborators are mocked, so the test can see which
 * operation the tool actually dispatched to.
 *
 * @return array{0: SubAgentTool, 1: HandoverServiceInterface&Mockery\MockInterface, 2: SubAgentServiceInterface&Mockery\MockInterface}
 */
function opResolutionTool(): array
{
    $handover = Mockery::mock(HandoverServiceInterface::class);
    $subAgent = Mockery::mock(SubAgentServiceInterface::class);
    $config   = Mockery::mock(ToolConfigServiceInterface::class);
    $config->allows('getEffectiveSettings')->andReturn(['allowed_target_agents' => [OP_RESOLUTION_TARGET_AGENT_ID]]);

    return [new SubAgentTool($handover, $subAgent, $config), $handover, $subAgent];
}

describe('ToolOperationResolver::resolveOperationName', function (): void {

    afterEach(function (): void {
        Mockery::close();
        Spora\Core\Database::resetBootState();
    });

    it('uses the discriminator when the LLM sent one', function (): void {
        $seed = opResolutionSeedAgent();
        [$tool, , ] = opResolutionTool();
        $orch = opResolutionOrchestrator([$tool]);

        expect((new ToolOperationResolver())->resolveOperationName($tool, ['op' => 'handover'], $seed['agentId']))->toBe('handover');
    });

    it('uses the only enabled operation when the discriminator is absent', function (): void {
        $seed = opResolutionSeedAgent();
        [$tool, , ] = opResolutionTool();
        $orch = opResolutionOrchestrator([$tool]);

        AgentToolOperationOverride::create([
            'agent_id'  => $seed['agentId'],
            'tool_class' => SubAgentTool::class,
            'operation' => 'handover',
            'enabled'   => 1,
        ]);
        AgentToolOperationOverride::create([
            'agent_id'  => $seed['agentId'],
            'tool_class' => SubAgentTool::class,
            'operation' => 'sub_agent',
            'enabled'   => 0,
        ]);
        AgentToolOperationOverride::create([
            'agent_id'  => $seed['agentId'],
            'tool_class' => SubAgentTool::class,
            'operation' => 'sub_agent_continue',
            'enabled'   => 0,
        ]);

        // `sub_agent` is the first declared operation, so the old fallback
        // would answer "sub_agent" — and the call would be refused because
        // that operation is disabled for this agent.
        expect((new ToolOperationResolver())->resolveOperationName($tool, [], $seed['agentId']))->toBe('handover');
    });

    it('falls back to the first declared operation when several are enabled', function (): void {
        $seed = opResolutionSeedAgent();
        [$tool, , ] = opResolutionTool();
        $orch = opResolutionOrchestrator([$tool]);

        // `sub_agent` is enabled by default; `sub_agent_continue` and
        // `handover` are opt-in.
        expect((new ToolOperationResolver())->resolveOperationName($tool, [], $seed['agentId']))->toBe('sub_agent');
    });
});

describe('a handover-only agent whose LLM omits the op discriminator', function (): void {

    afterEach(function (): void {
        Mockery::close();
        Spora\Core\Database::resetBootState();
    });

    it('dispatches to the handover operation', function (): void {
        $seed = opResolutionSeedAgent();
        [$tool, $handover, $subAgent] = opResolutionTool();
        $orch = opResolutionOrchestrator([$tool]);

        foreach ([['handover', 1], ['sub_agent', 0], ['sub_agent_continue', 0]] as [$operation, $enabled]) {
            AgentToolOperationOverride::create([
                'agent_id'                  => $seed['agentId'],
                'tool_class'                => SubAgentTool::class,
                'operation'                 => $operation,
                'enabled'                   => $enabled,
                'default_requires_approval' => 0,
            ]);
        }

        $newTask = new Task();
        $newTask->id = 4242;
        $handover->allows('handover')->andReturn($newTask);
        $subAgent->shouldNotReceive('spawn');

        $executor = new ToolCallExecutor($orch);

        // No `op` in the arguments — the LLM picked the only operation the
        // schema offered.
        $toolCall = new DriverToolCall('call_no_op', 'sub_agent', [
            'target_agent_id' => OP_RESOLUTION_TARGET_AGENT_ID,
            'prompt'          => 'take this over',
        ]);

        $disposition = $executor->executeOrQueue($toolCall, Agent::find($seed['agentId']), $seed['task']);

        expect($disposition)->toBe(ToolCallDisposition::Executed);

        // The spawn op never ran — the call went to the handover.
        $subAgent->shouldNotHaveReceived('spawn');

        $record = ToolCallModel::where('task_id', $seed['task']->id)->first();
        expect($record)->not->toBeNull()
            ->and($record->operation)->toBe('handover')
            ->and($record->status)->toBe('APPROVED')
            ->and($record->result_data['op'])->toBe('handover')
            ->and($record->result_data['handover'])->toBeTrue()
            ->and($record->result_data['new_task_id'])->toBe(4242);
    });
});
