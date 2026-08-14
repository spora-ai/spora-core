<?php

declare(strict_types=1);

use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\TaskLifecyclePolicy;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * Build a no-op DriverFactory returning a mock LLM driver.
 */
function makeAbortOrchestratorDriverFactory(): DriverFactory
{
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturn(new LLMResponse('Ok', [], 1, 1, 'cmp_x'));
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    return $factory;
}

function makeAbortOrchestrator(): Orchestrator
{
    return new Orchestrator(
        makeAbortOrchestratorDriverFactory(),
        new OrchestratorConfig(),
    );
}

/**
 * Create a user, agent, and a task with the given status. Returns the
 * task id. The agent is bare-bones (no LLM config) — abort() never
 * touches the LLM so this is fine.
 */
function seedTaskWithStatus(string $status, array $data = []): int
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('abort-' . $status . '@example.com', TEST_PASSWORD, 'Abort ' . $status);

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Abort Test Agent',
        'llm_driver_config_id' => null,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    $task = Task::create(array_merge([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => $status,
        'user_prompt' => 'orig',
        'step_count'  => 0,
        'max_steps'   => 5,
        'data'        => $data,
    ], $data !== [] ? [] : []));

    return $task->id;
}

// ---------------------------------------------------------------------------
// TaskLifecyclePolicy — static surface
// ---------------------------------------------------------------------------

it('TaskLifecyclePolicy.isTerminal recognises terminal statuses only', function (): void {
    $policy = new TaskLifecyclePolicy();

    expect($policy->isTerminal('COMPLETED'))->toBeTrue()
        ->and($policy->isTerminal('FAILED'))->toBeTrue()
        ->and($policy->isTerminal('CANCELLED'))->toBeTrue()
        ->and($policy->isTerminal('ABORTED'))->toBeFalse()
        ->and($policy->isTerminal('RUNNING'))->toBeFalse()
        ->and($policy->isTerminal('PENDING_APPROVAL'))->toBeFalse()
        ->and($policy->isTerminal('AWAITING_SUB_AGENTS'))->toBeFalse()
        ->and($policy->isTerminal('QUEUED'))->toBeFalse();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('TaskLifecyclePolicy.isQuiescent maps ABORTED + the existing paused states', function (): void {
    $policy = new TaskLifecyclePolicy();

    expect($policy->isQuiescent('ABORTED'))->toBeTrue()
        ->and($policy->isQuiescent('PENDING_APPROVAL'))->toBeTrue()
        ->and($policy->isQuiescent('AWAITING_SUB_AGENTS'))->toBeTrue()
        ->and($policy->isQuiescent('RUNNING'))->toBeFalse()
        ->and($policy->isQuiescent('COMPLETED'))->toBeFalse();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('TaskLifecyclePolicy.canAbortFrom allows only RUNNING and AWAITING_SUB_AGENTS', function (): void {
    $policy = new TaskLifecyclePolicy();

    expect($policy->canAbortFrom('RUNNING'))->toBeTrue()
        ->and($policy->canAbortFrom('AWAITING_SUB_AGENTS'))->toBeTrue()
        ->and($policy->canAbortFrom('PENDING_APPROVAL'))->toBeFalse()
        ->and($policy->canAbortFrom('COMPLETED'))->toBeFalse()
        ->and($policy->canAbortFrom('FAILED'))->toBeFalse()
        ->and($policy->canAbortFrom('ABORTED'))->toBeFalse()        // idempotent at the orchestrator level, not the service
        ->and($policy->canAbortFrom('QUEUED'))->toBeFalse();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('TaskLifecyclePolicy.canContinueFrom accepts the new sources only', function (): void {
    $policy = new TaskLifecyclePolicy();

    expect($policy->canContinueFrom('COMPLETED'))->toBeTrue()
        ->and($policy->canContinueFrom('FAILED'))->toBeTrue()
        ->and($policy->canContinueFrom('ABORTED'))->toBeTrue()
        ->and($policy->canContinueFrom('RUNNING'))->toBeTrue()
        ->and($policy->canContinueFrom('PENDING_APPROVAL'))->toBeFalse()
        ->and($policy->canContinueFrom('AWAITING_SUB_AGENTS'))->toBeFalse()
        ->and($policy->canContinueFrom('QUEUED'))->toBeFalse();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Orchestrator::abort — happy path
// ---------------------------------------------------------------------------

it('Orchestrator::abort flips RUNNING to ABORTED and persists data.aborted_at', function (): void {
    $taskId = seedTaskWithStatus('RUNNING');
    $orch = makeAbortOrchestrator();

    $task = $orch->abort($taskId);

    expect($task->status)->toBe('ABORTED');
    expect(is_array($task->data) ? $task->data : [])->toHaveKey('aborted_at');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('Orchestrator::abort flips AWAITING_SUB_AGENTS to ABORTED', function (): void {
    $taskId = seedTaskWithStatus('AWAITING_SUB_AGENTS', [
        'data' => ['spawned_sub_task_ids' => [99], 'sub_agent_expected_count' => 1, 'run_id' => null],
    ]);
    // Re-create with the data payload attached
    $task = Task::find($taskId);
    $task->data = ['spawned_sub_task_ids' => [99], 'sub_agent_expected_count' => 1];
    $task->save();

    $orch = makeAbortOrchestrator();
    $aborted = $orch->abort($taskId);

    expect($aborted->status)->toBe('ABORTED')
        ->and($aborted->data['aborted_at'] ?? null)->not->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('Orchestrator::abort is idempotent — calling on an ABORTED row leaves it untouched', function (): void {
    $taskId = seedTaskWithStatus('RUNNING');
    $orch = makeAbortOrchestrator();

    $orch->abort($taskId);
    $aborted = $orch->abort($taskId);

    expect($aborted->status)->toBe('ABORTED');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Orchestrator::abort — guard rails
// ---------------------------------------------------------------------------

it('Orchestrator::abort rejects PENDING_APPROVAL with InvalidTaskTransitionException', function (): void {
    $taskId = seedTaskWithStatus('PENDING_APPROVAL');
    $orch = makeAbortOrchestrator();

    expect(fn() => $orch->abort($taskId))
        ->toThrow(InvalidTaskTransitionException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('Orchestrator::abort rejects COMPLETED with InvalidTaskTransitionException', function (): void {
    $taskId = seedTaskWithStatus('COMPLETED');
    $orch = makeAbortOrchestrator();

    expect(fn() => $orch->abort($taskId))
        ->toThrow(InvalidTaskTransitionException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('Orchestrator::abort rejects FAILED with InvalidTaskTransitionException', function (): void {
    $taskId = seedTaskWithStatus('FAILED');
    $orch = makeAbortOrchestrator();

    expect(fn() => $orch->abort($taskId))
        ->toThrow(InvalidTaskTransitionException::class);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Orchestrator::continue — relaxed guard + abort-marker
// ---------------------------------------------------------------------------

it('Orchestrator::continue on RUNNING source flips to ABORTED, appends marker, no tick', function (): void {
    $taskId = seedTaskWithStatus('RUNNING');
    $orch = makeAbortOrchestrator();

    $continued = $orch->continue($taskId, 'redirect please');

    expect($continued->status)->toBe('ABORTED')
        ->and($continued->user_prompt)->toBe('redirect please')
        ->and($continued->step_count)->toBe(0);

    $marker = TaskHistory::where('task_id', $taskId)
        ->where('role', 'system')
        ->where('content', 'like', '%abort_marker%')
        ->first();
    expect($marker)->not->toBeNull()
        ->and(json_decode((string) $marker->content, true))->toMatchArray(['kind' => 'abort_marker'])
        ->and($marker->content)->toContain('"at":');

    $userRow = TaskHistory::where('task_id', $taskId)
        ->where('role', 'user')
        ->where('content', 'redirect please')
        ->first();
    expect($userRow)->not->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('Orchestrator::continue on ABORTED source clears aborted_at and re-prompts', function (): void {
    // Set up an LLM config so the post-ABORTED tick doesn't blow up
    $config = Spora\Models\LLMDriverConfiguration::create([
        'user_id'           => null,
        'name'              => 'Test Global Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $authService = bootAuthLayer();
    $userId = $authService->register('cont-aborted@example.com', TEST_PASSWORD, 'Cont Aborted');

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Aborted Continue Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    $task = Task::create([
        'agent_id'    => $agent->id,
        'user_id'     => $userId,
        'status'      => 'ABORTED',
        'user_prompt' => 'orig',
        'step_count'  => 0,
        'max_steps'   => 5,
        'data'        => ['aborted_at' => '2026-08-08 12:00:00'],
    ]);

    $orch = makeAbortOrchestrator();
    $continued = $orch->continue($task->id, 'try again');

    expect($continued->status)->toBe('COMPLETED')
        ->and($continued->user_prompt)->toBe('try again')
        ->and($continued->final_response)->toBe('Ok');

    $refreshed = Task::find($task->id);
    expect($refreshed->data['aborted_at'] ?? null)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('Orchestrator::continue rejects PENDING_APPROVAL with the new error message', function (): void {
    $taskId = seedTaskWithStatus('PENDING_APPROVAL');
    $orch = makeAbortOrchestrator();

    expect(fn() => $orch->continue($taskId, 'try'))
        ->toThrow(InvalidTaskTransitionException::class, 'Can only continue completed, failed, aborted, or running tasks.');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

// ---------------------------------------------------------------------------
// Abort → Continue — retry-chain and failure-field reset
// ---------------------------------------------------------------------------

it('Orchestrator::continue from ABORTED clears stale retry_of_task_id so the worker claim predicate matches', function (): void {
    // Regression: when the auto-retry chain had scheduled a retry
    // (`retry_of_task_id IS NOT NULL`), and the operator then aborted
    // before `retry_after` elapsed, and *then* pressed Continue, the
    // task was left in QUEUED with `retry_of_task_id` still set. The
    // worker's claim predicate
    //
    //   SELECT … FROM tasks WHERE status = 'QUEUED'
    //     AND retry_of_task_id IS NULL
    //
    // would skip the row forever, and `processRetryQueue` only
    // matches FAILED, so the QUEUED row was stranded with no worker
    // path capable of picking it up. Apply continues now resets the
    // retry-chain markers alongside aborted_at.
    $config = Spora\Models\LLMDriverConfiguration::create([
        'user_id'           => null,
        'name'              => 'Test Global Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $authService = bootAuthLayer();
    $userId = $authService->register(
        'abort-continue-retrychain@example.com',
        TEST_PASSWORD,
        'Abort Continue RetryChain',
    );

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'RetryChain Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    // Aborted task with a stale retry chain marker from a previous
    // failure — exactly the row the worker was skipping.
    $task = Task::create([
        'agent_id'         => $agent->id,
        'user_id'          => $userId,
        'status'           => 'ABORTED',
        'user_prompt'      => 'orig',
        'step_count'       => 0,
        'max_steps'        => 5,
        'retry_of_task_id' => $agent->id, // any non-null marker from prior retry
        'retry_after'      => '2099-01-01 00:00:00',
        'error_code'       => 'RATE_LIMIT',
        'error_message'    => 'slow upstream',
        'failure_reason'   => '429 too many',
        'data'             => ['aborted_at' => '2026-08-08 12:00:00'],
    ]);

    $orch = makeAbortOrchestrator();
    $orch->continue($task->id, 'pick this up');

    $fresh = Task::find($task->id);
    // The worker can now match the row.
    expect($fresh->status)->toBe('COMPLETED')
        ->and($fresh->retry_of_task_id)->toBeNull()
        ->and($fresh->retry_after)->toBeNull()
        ->and($fresh->error_code)->toBeNull()
        ->and($fresh->error_message)->toBeNull()
        ->and($fresh->failure_reason)->toBeNull()
        ->and($fresh->data['aborted_at'] ?? null)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('Orchestrator::continue from COMPLETED also clears stale retry markers', function (): void {
    // The retry-chain reset applies to both branches of continue() —
    // if the user continues a COMPLETED task whose auto-retry still
    // has a marker, the worker should still be able to claim the row.
    $config = Spora\Models\LLMDriverConfiguration::create([
        'user_id'           => null,
        'name'              => 'Test Global Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $authService = bootAuthLayer();
    $userId = $authService->register(
        'cont-completed-retrychain@example.com',
        TEST_PASSWORD,
        'Continue Completed RetryChain',
    );

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Completed RetryChain Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    $task = Task::create([
        'agent_id'         => $agent->id,
        'user_id'          => $userId,
        'status'           => 'COMPLETED',
        'user_prompt'      => 'old',
        'final_response'   => 'done',
        'step_count'       => 1,
        'max_steps'        => 5,
        'retry_of_task_id' => $agent->id,
        'retry_after'      => '2099-01-01 00:00:00',
    ]);

    $orch = makeAbortOrchestrator();
    $orch->continue($task->id, 'one more turn');

    $fresh = Task::find($task->id);
    expect($fresh->retry_of_task_id)->toBeNull()
        ->and($fresh->retry_after)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());
