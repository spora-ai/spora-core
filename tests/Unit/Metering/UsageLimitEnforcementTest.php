<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Mockery;
use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ToolCallBatchHandler;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Metering\Exceptions\UsageLimitExceededException;
use Spora\Metering\UsageDecision;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Tests\Unit\Agents\SpySafeExecuteTool;

/**
 * Enforcement, end to end.
 *
 * A limit that is reported as a tool error is not a limit: the model reads
 * "the tool failed", tries a different tool, and spends again. Every
 * assertion here is about the failure reaching the task row instead of the
 * transcript.
 */
beforeEach(function (): void {
    SpySafeExecuteTool::$lastTaskId = null;
});

/**
 * @return array{0: Agent, 1: int}
 */
function meteringEnforcementSeed(): array
{
    $userId = bootAuthLayer()->register('metering-enforce@example.com', 'Password1!', 'Metering');

    $config = LLMDriverConfiguration::create([
        'principal_id'      => null,
        'name'              => 'Metering Test Config',
        'driver_class'      => OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'         => createUserPrincipalPublic($userId),
        'name'                 => 'Metering Test Agent',
        'llm_driver_config_id' => $config->id,
        'system_prompt'        => 'You are a helpful AI assistant.',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => SpySafeExecuteTool::class,
        'tool_name'  => 'spy_safe_execute',
    ]);

    return [$agent, $userId];
}

function meteringEnforcementTask(Agent $agent, int $userId): Task
{
    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'meter me',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 0,
        'role'     => 'user',
        'content'  => 'meter me',
    ]);

    return $task;
}

function meteringEnforcementOrchestrator(StubUsageMeter $meter): Orchestrator
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('getProviderName')->andReturn('openai_compatible');
    $driver->allows('getModelName')->andReturn('gpt-4o');
    $driver->allows('complete')->andReturn(
        new \Spora\Drivers\ValueObjects\LLMResponse('Hi.', [], 5, 3, 'cmp_enforce'),
    );

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    return new Orchestrator(
        $factory,
        new OrchestratorConfig(
            toolInstances: [new SpySafeExecuteTool()],
            logger: new NullLogger(),
            usageMeter: $meter,
        ),
    );
}

it('stops a tool call instead of handing the model a tool error', function (): void {
    [$agent, $userId] = meteringEnforcementSeed();
    $task = meteringEnforcementTask($agent, $userId);

    $meter = new StubUsageMeter();
    $meter->decision = new UsageDecision('Daily tool-call cap reached.', UsageDecision::MODE_HARD, 'day:tool_calls');

    $orchestrator = meteringEnforcementOrchestrator($meter);

    $thrown = null;

    try {
        $orchestrator->safeExecute(new SpySafeExecuteTool(), [], $agent->id, (int) $task->id);
    } catch (UsageLimitExceededException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->decision()->reason)->toBe('Daily tool-call cap reached.')
        ->and($thrown->decision()->scope)->toBe('day:tool_calls')
        // The tool never ran.
        ->and(SpySafeExecuteTool::$lastTaskId)->toBeNull();
});

it('records the tool call after it ran', function (): void {
    [$agent, $userId] = meteringEnforcementSeed();
    $task = meteringEnforcementTask($agent, $userId);

    $meter = new StubUsageMeter();

    $orchestrator = meteringEnforcementOrchestrator($meter);
    $orchestrator->safeExecute(new SpySafeExecuteTool(), [], $agent->id, (int) $task->id);

    expect($meter->toolUsage)->toHaveCount(1)
        ->and($meter->toolUsage[0][1]->toolName)->toBe('spy_safe_execute')
        ->and($meter->toolUsage[0][1]->toolClass)->toBe(SpySafeExecuteTool::class)
        ->and($meter->toolUsage[0][1]->succeeded)->toBeTrue()
        ->and($meter->toolUsage[0][0]->taskId)->toBe((int) $task->id);
});

it('lets the tool run when the meter returns a soft decision', function (): void {
    [$agent, $userId] = meteringEnforcementSeed();
    $task = meteringEnforcementTask($agent, $userId);

    $meter = new StubUsageMeter();
    $meter->decision = new UsageDecision('Halfway to the daily cap.', UsageDecision::MODE_SOFT, 'day:tool_calls');

    $orchestrator = meteringEnforcementOrchestrator($meter);
    $result = $orchestrator->safeExecute(new SpySafeExecuteTool(), [], $agent->id, (int) $task->id);

    expect($result->success)->toBeTrue()
        ->and(SpySafeExecuteTool::$lastTaskId)->toBe((int) $task->id);
});

it('lets the tool run when the meter itself throws', function (): void {
    [$agent, $userId] = meteringEnforcementSeed();
    $task = meteringEnforcementTask($agent, $userId);

    $meter = new StubUsageMeter();
    $meter->evaluateThrows = new RuntimeException('the price book is corrupt');

    $orchestrator = meteringEnforcementOrchestrator($meter);
    $result = $orchestrator->safeExecute(new SpySafeExecuteTool(), [], $agent->id, (int) $task->id);

    expect($result->success)->toBeTrue()
        ->and(SpySafeExecuteTool::$lastTaskId)->toBe((int) $task->id);
});

it('fails the task with USAGE_LIMIT_REACHED and schedules no retry', function (): void {
    [$agent, $userId] = meteringEnforcementSeed();
    $task = meteringEnforcementTask($agent, $userId);

    $meter = new StubUsageMeter();
    $meter->decision = new UsageDecision('Monthly credit limit reached.', UsageDecision::MODE_HARD, 'month:credits');

    $orchestrator = meteringEnforcementOrchestrator($meter);
    $orchestrator->tick((int) $task->id);

    $task->refresh();

    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('USAGE_LIMIT_REACHED')
        ->and($task->failure_reason)->toBe('Monthly credit limit reached.')
        ->and($task->error_message)->toBe('Monthly credit limit reached.')
        // A limit is terminal: a scheduled retry would spend the very
        // tokens the limit exists to stop.
        ->and($task->retry_after)->toBeNull()
        ->and((int) $task->retry_count)->toBe(0);
});

it('does not turn a limit into a tool-error history row', function (): void {
    [$agent, $userId] = meteringEnforcementSeed();
    $task = meteringEnforcementTask($agent, $userId);

    $meter = new StubUsageMeter();
    $meter->decision = new UsageDecision('Daily tool-call cap reached.', UsageDecision::MODE_HARD, 'day:tool_calls');

    $orchestrator = meteringEnforcementOrchestrator($meter);

    $handler = new ToolCallBatchHandler(orchestrator: $orchestrator, logger: new NullLogger());

    $thrown = null;

    try {
        $handler->dispatchToolCalls($task, $agent, [
            new DriverToolCall('call_metered', 'spy_safe_execute', []),
        ]);
    } catch (UsageLimitExceededException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();

    // A 'System Error' tool row would read as "the tool failed", and the
    // model would try something else — which is what the limit forbids.
    $toolRows = TaskHistory::where('task_id', $task->id)->where('role', 'tool')->get();

    expect($toolRows)->toHaveCount(0);
});
