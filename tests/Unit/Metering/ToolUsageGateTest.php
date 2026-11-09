<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Psr\Log\NullLogger;
use Spora\Agents\ToolOperationResolver;
use Spora\Agents\ToolUsageGate;
use Spora\Metering\MeteringContextFactory;
use Spora\Metering\MeteringGuard;
use Spora\Metering\NullUsageMeter;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Tests\Unit\Agents\SpySafeExecuteTool;

/**
 * The gate's own contract.
 *
 * The scope is null when there is no meter to talk to, and that null is what
 * keeps the seam invisible on an install without a metering plugin: no
 * query for the context, no read of the agent's operation overrides, no
 * call the agent turn did not already make.
 */
function toolUsageGateSeed(): array
{
    $userId = bootAuthLayer()->register('metering-gate@example.com', 'Password1!', 'Metering');

    $config = LLMDriverConfiguration::create([
        'principal_id'      => null,
        'name'              => 'Metering Gate Config',
        'driver_class'      => \Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'         => createUserPrincipalPublic($userId),
        'name'                 => 'Metering Gate Agent',
        'llm_driver_config_id' => $config->id,
        'system_prompt'        => 'You are a helpful AI assistant.',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'meter me',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    return [$agent, $task];
}

it('has no scope at all when no meter is installed', function (): void {
    [$agent, $task] = toolUsageGateSeed();

    $gate = new ToolUsageGate(
        new MeteringGuard(new NullUsageMeter(), new NullLogger()),
        new MeteringContextFactory(),
        new ToolOperationResolver(),
    );

    $scope = $gate->scopeFor(
        (int) $task->id,
        'spy_safe_execute',
        SpySafeExecuteTool::class,
        new SpySafeExecuteTool(),
        [],
        (int) $agent->id,
    );

    expect($scope)->toBeNull();
});

it('names the tool and the operation it dispatches to', function (): void {
    [$agent, $task] = toolUsageGateSeed();

    $gate = new ToolUsageGate(
        new MeteringGuard(new StubUsageMeter(), new NullLogger()),
        new MeteringContextFactory(),
        new ToolOperationResolver(),
    );

    $scope = $gate->scopeFor(
        (int) $task->id,
        'spy_safe_execute',
        SpySafeExecuteTool::class,
        new SpySafeExecuteTool(),
        [],
        (int) $agent->id,
    );

    expect($scope)->not->toBeNull()
        ->and($scope->toolName)->toBe('spy_safe_execute')
        ->and($scope->toolClass)->toBe(SpySafeExecuteTool::class)
        ->and($scope->operation)->toBe('default')
        ->and($scope->context->taskId)->toBe((int) $task->id)
        ->and($scope->context->principalId)->toBe((int) $task->principal_id)
        ->and($scope->context->triggerUserId)->toBe((int) $task->trigger_user_id);
});

it('has no scope for a task that does not exist', function (): void {
    $gate = new ToolUsageGate(
        new MeteringGuard(new StubUsageMeter(), new NullLogger()),
        new MeteringContextFactory(),
        new ToolOperationResolver(),
    );

    $scope = $gate->scopeFor(
        999_999_999,
        'spy_safe_execute',
        SpySafeExecuteTool::class,
        new SpySafeExecuteTool(),
        [],
        1,
    );

    expect($scope)->toBeNull();
});
