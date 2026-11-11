<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Mockery;
use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Metering\UsageDecision;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\SubAgentService;

/**
 * A child that hits a limit still has to release its parent.
 *
 * The parent waits in `AWAITING_SUB_AGENTS` until every child is
 * terminal. A child that fails on a limit *is* terminal — so the parent's
 * wait is over and the parent has to resume with the failure as the tool
 * result. The failure path already does that; the limit path did not, so
 * the parent stayed waiting for a child that was never coming back.
 */
function limitChildSeed(): array
{
    $ownerId = bootAuthLayer()->register('metering-child@example.com', 'Password1!', 'Metering');
    $principalId = createUserPrincipalPublic($ownerId);

    $config = LLMDriverConfiguration::create([
        'principal_id'      => null,
        'name'              => 'Metering Child Config',
        'driver_class'      => \Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $childAgent = Agent::create([
        'principal_id'         => $principalId,
        'name'                 => 'Metering Child Agent',
        'llm_driver_config_id' => $config->id,
        'system_prompt'        => 'You are a helpful AI assistant.',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $parent = Task::create([
        'agent_id'        => $childAgent->id,
        'principal_id'    => $principalId,
        'trigger_user_id' => $ownerId,
        'status'          => 'AWAITING_SUB_AGENTS',
        'user_prompt'     => 'wait for the child',
        'step_count'      => 0,
        'max_steps'       => 10,
        'data'            => [
            'sub_agent_expected_count' => 1,
            'spawned_sub_task_ids'     => [],
            'sub_agent_batch_open'     => false,
        ],
    ]);

    $child = Task::create([
        'agent_id'        => $childAgent->id,
        'principal_id'    => $principalId,
        'trigger_user_id' => $ownerId,
        'parent_task_id'  => $parent->id,
        'status'          => 'RUNNING',
        'user_prompt'     => 'run as a child',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    $data = $parent->data ?? [];
    $data['spawned_sub_task_ids'] = [(int) $child->id];
    $parent->data = $data;
    $parent->save();

    return [$parent, $child, $childAgent, $ownerId];
}

it('resumes the parent when a child reaches a usage limit', function (): void {
    [$parent, $child, $childAgent] = limitChildSeed();

    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->never();
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $driver->allows('supportsImageInput')->andReturn(false);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    // The resume writes the child's answer into the parent's history.
    $parentOrchestrator = Mockery::mock(OrchestratorInterface::class);
    $parentOrchestrator->shouldReceive('appendHistory')->zeroOrMoreTimes();

    $meter = new StubUsageMeter();
    $meter->decision = new UsageDecision(
        'Monthly credit limit reached.',
        UsageDecision::MODE_HARD,
        'month:credits',
    );

    $orchestrator = new Orchestrator(
        $factory,
        new OrchestratorConfig(
            logger: new NullLogger(),
            usageMeter: $meter,
            subAgent: new SubAgentService(static fn(): OrchestratorInterface => $parentOrchestrator),
        ),
    );

    $orchestrator->tick((int) $child->id);

    $child->refresh();
    $parent->refresh();

    expect($child->status)->toBe('FAILED')
        ->and($child->error_code)->toBe('USAGE_LIMIT_REACHED')
        // The parent was waiting for this child, and the child is gone.
        // It has to be released — otherwise it waits for a child that is
        // never coming back.
        ->and($parent->status)->toBe('QUEUED');
});
