<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

const RECOVERY_SUMMARY_PROMPT = 'You are a helpful assistant that summarises conversations.';

/**
 * An agent, a running task and the history a context-window error arrives on.
 *
 * @return array{0: Agent, 1: Task}
 */
function seedContextWindowRecoveryTask(): array
{
    $userId = bootAuthLayer()->register('cwr-recovery@example.com', TEST_PASSWORD, 'CWR');
    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'              => 'CWR Recovery Test Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);
    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'                 => 'CWR Recovery Test Agent',
        'llm_driver_config_id' => $config->id,
        'system_prompt'        => 'You are a helpful AI assistant.',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'agent_id'    => $agent->id,
        'status'      => 'RUNNING',
        'user_prompt' => 'compact test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    return [$agent, $task];
}

/**
 * A driver that answers the summariser with `$summary` and the agent's own
 * turn with a plain response, counting the calls it received.
 *
 * @param  int  $calls  Filled with the number of LLM calls.
 */
function recoveryDriver(int &$calls, string $summary = 'Earlier summary.'): LLMDriverInterface
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andReturnUsing(static function (LLMRequest $request) use (&$calls, $summary): LLMResponse {
        $calls++;

        if ($request->systemPrompt === RECOVERY_SUMMARY_PROMPT) {
            return new LLMResponse($summary, [], 10, 5, 'cmp_summary');
        }

        return new LLMResponse('Recovered after compaction.', [], 10, 5, 'cmp_done');
    });
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $driver->allows('supportsImageInput')->andReturn(false);

    return $driver;
}

function recoveryOrchestrator(DriverFactory $factory): Orchestrator
{
    return new Orchestrator($factory, new OrchestratorConfig(toolInstances: []));
}

it('re-throws the original error and does not re-tick when the compaction writes nothing', function (): void {
    [$agent, $task] = seedContextWindowRecoveryTask();

    // A compaction has already happened: the history holds the five rows
    // after the summarised range plus the summary row itself. There is
    // nothing left to summarise, so the compaction writes nothing and the
    // history stays as it is.
    for ($i = 0; $i < 8; $i++) {
        TaskHistory::create([
            'task_id'  => $task->id,
            'sequence' => $i,
            'role'     => $i % 2 === 0 ? 'user' : 'assistant',
            'content'  => "Message {$i}",
        ]);
    }
    TaskHistory::create([
        'task_id'                   => $task->id,
        'sequence'                  => 8,
        'role'                      => 'summary',
        'content'                   => 'Earlier summary.',
        'summarized_sequence_range' => '0-2',
    ]);

    $calls  = 0;
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn(recoveryDriver($calls));

    $orch     = recoveryOrchestrator($factory);
    $original = new RuntimeException('Context window exceeded');

    $thrown = null;
    try {
        $orch->contextWindowRecovery->tryCompactionAndRetry($task, $agent, $original);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // The original error, not a compaction error: the turn has to fail.
    expect($thrown)->toBe($original);

    // Neither the summariser nor a re-tick reached the model — a retry
    // would hit the same context-window error.
    expect($calls)->toBe(0)
        ->and(TaskHistory::where('task_id', $task->id)->count())->toBe(9)
        ->and(TaskHistory::where('task_id', $task->id)->where('role', 'summary')->count())->toBe(1);
});

it('re-throws the original error when the compaction itself fails', function (): void {
    [$agent, $task] = seedContextWindowRecoveryTask();

    for ($i = 0; $i < 8; $i++) {
        TaskHistory::create([
            'task_id'  => $task->id,
            'sequence' => $i,
            'role'     => $i % 2 === 0 ? 'user' : 'assistant',
            'content'  => "Message {$i}",
        ]);
    }

    // A reasoning model can spend its whole budget thinking and return no
    // text, which the compactor reports as a failed compaction.
    $calls  = 0;
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn(recoveryDriver($calls, ''));

    $orch     = recoveryOrchestrator($factory);
    $original = new RuntimeException('Context window exceeded');

    $thrown = null;
    try {
        $orch->contextWindowRecovery->tryCompactionAndRetry($task, $agent, $original);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBe($original)
        // The summary request is the only call: the failed compaction ends
        // the turn, it does not start a new one.
        ->and($calls)->toBe(1)
        ->and(TaskHistory::where('task_id', $task->id)->where('role', 'summary')->count())->toBe(0);
});

it('re-ticks when the compaction wrote a summary', function (): void {
    [$agent, $task] = seedContextWindowRecoveryTask();

    for ($i = 0; $i < 8; $i++) {
        TaskHistory::create([
            'task_id'  => $task->id,
            'sequence' => $i,
            'role'     => $i % 2 === 0 ? 'user' : 'assistant',
            'content'  => "Message {$i}",
        ]);
    }

    $calls  = 0;
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn(recoveryDriver($calls));

    $orch = recoveryOrchestrator($factory);

    $orch->contextWindowRecovery->tryCompactionAndRetry($task, $agent, new RuntimeException('Context window exceeded'));

    // One call for the summary, one for the re-ticked turn.
    expect($calls)->toBe(2);

    $summary = TaskHistory::where('task_id', $task->id)->where('role', 'summary')->first();
    expect($summary)->not->toBeNull()
        ->and($summary->content)->toBe('Earlier summary.');

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->final_response)->toBe('Recovered after compaction.');
});
