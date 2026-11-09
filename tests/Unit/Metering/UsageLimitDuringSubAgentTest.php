<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Mockery;
use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Metering\UsageDecision;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * A limit that arrives while the task is waiting for its sub-agents.
 *
 * `SubAgentService::spawn()` flips the parent to `AWAITING_SUB_AGENTS`
 * before the child runs, so a parent that spawned a child mid-batch is no
 * longer `RUNNING` when the next tool in that batch is metered.
 *
 * The limit's write is a compare-and-set, so a status outside the set
 * means the write changes no row — and the refusal is swallowed: the
 * parent keeps waiting, the budget the limit exists to stop keeps being
 * spent, and the interrupted batch never reaches its resume hook.
 */
#[Tool(name: 'spawning_tool', description: 'Leaves the task waiting for sub-agents')]
#[ToolOperation(name: 'default', description: 'Spawn', enabledByDefault: true, requiresApprovalByDefault: false)]
final class SpawningTool implements ToolInterface
{
    use HasOperations;

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        Task::where('id', $taskId)->update(['status' => 'AWAITING_SUB_AGENTS']);

        return new ToolResult(true, 'spawned');
    }

    public function describeAction(array $arguments): string
    {
        return 'Leave the task waiting for sub-agents';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}

#[Tool(name: 'refused_tool', description: 'Never runs — the limit refuses it first')]
#[ToolOperation(name: 'default', description: 'Run', enabledByDefault: true, requiresApprovalByDefault: false)]
final class RefusedTool implements ToolInterface
{
    use HasOperations;

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'ran');
    }

    public function describeAction(array $arguments): string
    {
        return 'Never runs';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}

function subAgentLimitSeed(): array
{
    $userId = bootAuthLayer()->register('metering-subagent@example.com', 'Password1!', 'Metering');

    $config = LLMDriverConfiguration::create([
        'principal_id'      => null,
        'name'              => 'Metering SubAgent Config',
        'driver_class'      => \Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'         => createUserPrincipalPublic($userId),
        'name'                 => 'Metering SubAgent Agent',
        'llm_driver_config_id' => $config->id,
        'system_prompt'        => 'You are a helpful AI assistant.',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    foreach ([SpawningTool::class => 'spawning_tool', RefusedTool::class => 'refused_tool'] as $class => $name) {
        AgentTool::create([
            'agent_id'   => $agent->id,
            'tool_class' => $class,
            'tool_name'  => $name,
        ]);
    }

    return [$agent, $userId];
}

it('fails a task that is waiting for its sub-agents when a limit is reached', function (): void {
    [$agent, $userId] = subAgentLimitSeed();

    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'meter me',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andReturn(
        new LLMResponse(
            null,
            [
                new DriverToolCall('call_spawn', 'spawning_tool', []),
                new DriverToolCall('call_refused', 'refused_tool', []),
            ],
            10,
            5,
            'cmp_subagent',
        ),
    );
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $driver->allows('supportsImageInput')->andReturn(false);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    // The LLM turn is allowed, the first tool is allowed, the second is not.
    $meter = new StubUsageMeter();
    $meter->decisionSequence = [
        null,
        null,
        new UsageDecision('Monthly credit limit reached.', UsageDecision::MODE_HARD, 'month:credits'),
    ];

    $tools = [new SpawningTool(), new RefusedTool()];

    $orchestrator = new Orchestrator(
        $factory,
        new OrchestratorConfig(
            toolInstances: $tools,
            logger: new NullLogger(),
            usageMeter: $meter,
        ),
    );

    $orchestrator->tick((int) $task->id);

    $task->refresh();

    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('USAGE_LIMIT_REACHED')
        ->and($task->failure_reason)->toBe('Monthly credit limit reached.')
        // A limit is terminal: a scheduled retry would spend the very
        // budget the decision refused.
        ->and($task->retry_after)->toBeNull();
});
