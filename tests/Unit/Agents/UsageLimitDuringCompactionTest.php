<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Mockery;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Metering\UsageDecision;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Tests\Unit\Metering\StubUsageMeter;

/**
 * A limit that arrives during the *recovery* of another failure.
 *
 * The turn fails with a context-window error, so the orchestrator compacts
 * the history and retries. The compaction is a provider call of its own, and
 * the meter is asked about it too — so the limit can arrive there.
 *
 * The refusal must still land as `USAGE_LIMIT_REACHED` on the task row.
 * Reported as a context-window error instead, the task would look like a
 * window problem and the pending approvals would stay open forever.
 */
defined('COMPACTION_LIMIT_PASSWORD') || define('COMPACTION_LIMIT_PASSWORD', 'Password1!');

function compactionLimitSeed(): array
{
    $userId = bootAuthLayer()->register('compaction-limit@example.com', COMPACTION_LIMIT_PASSWORD, 'CWL');

    $config = LLMDriverConfiguration::create([
        'principal_id'      => null,
        'name'              => 'CWL Test Config',
        'driver_class'      => \Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'         => createUserPrincipalPublic($userId),
        'name'                 => 'CWL Test Agent',
        'llm_driver_config_id' => $config->id,
        'system_prompt'        => 'You are a helpful AI assistant.',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $task = Task::create([
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'        => $agent->id,
        'status'          => 'RUNNING',
        'user_prompt'     => 'compact test',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    return [$agent, $task];
}

it('fails the task with USAGE_LIMIT_REACHED when the recovery compaction hits a limit', function (): void {
    [$agent, $task] = compactionLimitSeed();

    // Long enough that the compaction has something to summarise — the
    // compactor keeps the last five rows verbatim and needs more than that
    // before it asks the model for a summary.
    for ($i = 0; $i < 8; $i++) {
        TaskHistory::create([
            'task_id'  => $task->id,
            'sequence' => $i,
            'role'     => $i % 2 === 0 ? 'user' : 'assistant',
            'content'  => "Message {$i}",
        ]);
    }

    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andThrow(
        new LLMProviderException('Provider API error 400: {"error":{"message":"maximum context length"}}'),
    );
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $driver->allows('supportsImageInput')->andReturn(false);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    // The agent's turn is allowed; the compaction that follows is not.
    $meter = new StubUsageMeter();
    $meter->decisionSequence = [
        null,
        new UsageDecision('Monthly credit limit reached.', UsageDecision::MODE_HARD, 'month:credits'),
    ];

    $orch = new Orchestrator($factory, new OrchestratorConfig(usageMeter: $meter));

    $orch->tick((int) $task->id);

    $task->refresh();

    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('USAGE_LIMIT_REACHED')
        ->and($task->failure_reason)->toBe('Monthly credit limit reached.')
        ->and($task->retry_after)->toBeNull()
        ->and((int) $task->retry_count)->toBe(0);

    // The meter was asked twice: once for the agent's turn, once for the
    // compaction the context-window error triggered.
    expect($meter->evaluations)->toHaveCount(2);
});
