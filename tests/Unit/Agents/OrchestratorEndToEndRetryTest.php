<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\Orchestrator;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;

/**
 * End-to-end retry chain through the real Orchestrator + RetryScheduler.
 *
 * The RetryScheduler has focused unit coverage in OrchestratorTest.php (3 tests
 * for `scheduleAutoRetry`), but the chain `failure → schedule → worker pickup
 * → Orchestrator::retry → next tick → either COMPLETED or another FAILED`
 * is only tested as fragments. A regression in any link (e.g. someone
 * changing `retry()` to skip clearing `retry_after`, or the scheduler
 * forgetting to bump `retry_count` on the in-place dispatch) would slip
 * through today. This file exercises the chain as a flow.
 */

defined('E2E_RETRY_TEST_PASSWORD') || define('E2E_RETRY_TEST_PASSWORD', 'Password1!');

function e2eRetrySeedAgent(int $retryAfterMinutes = 5, int $maxRetries = 2): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('e2e-retry@example.com', E2E_RETRY_TEST_PASSWORD, 'E2E Retry');

    $config = LLMDriverConfiguration::create([
        'principal_id'      => null,
        'name'              => 'Test Global Config',
        'driver_class'      => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'        => createUserPrincipalPublic($userId),
        'name'                => 'Test Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'           => 10,
        'is_active'           => true,
        'retry_after_minutes' => $retryAfterMinutes,
        'max_retries'         => $maxRetries,
    ]);

    return [$agent->id, $userId];
}

function e2eRetryMakeOrchestrator(LLMDriverInterface $driver): Orchestrator
{
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    return new Orchestrator($factory);
}

function e2eRetryCreateRunningTask(int $agentId, int $userId, string $prompt): Task
{
    $task = Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => $prompt,
        'step_count'      => 0,
        'max_steps'       => 10,
        'retry_count'     => 0,
    ]);

    // Pre-existing test fixtures seed a 'user' history row alongside the
    // task (mirrors what Orchestrator::start() does via appendHistory).
    // The history-preservation test depends on it being there from the
    // beginning so the failed tick's assistant row is observed alongside
    // the original prompt.
    TaskHistory::create([
        'task_id' => $task->id,
        'sequence' => 0,
        'role'     => 'user',
        'content'  => $prompt,
    ]);

    return $task;
}

it('end-to-end: rate-limit failure → retry() → next tick succeeds, LLM called twice', function (): void {
    [$agentId, $userId] = e2eRetrySeedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            throw new LLMRateLimitException('429 rate limit');
        }
        return new LLMResponse('Recovered.', [], 5, 3, 'cmp_retry_success');
    });

    $orch = e2eRetryMakeOrchestrator($mock);
    $task = e2eRetryCreateRunningTask($agentId, $userId, 'fail then recover');

    // First tick: rate-limit. TickPhaseRunner marks the task FAILED and
    // calls RetryScheduler::scheduleAutoRetry, which writes retry_after
    // on the same row. The exception still bubbles out of Orchestrator::tick.
    try {
        $orch->tick($task->id);
        PHPUnit\Framework\Assert::fail('Expected LLMRateLimitException to propagate');
    } catch (LLMRateLimitException) {
        // expected
    }

    $task->refresh();
    expect($task->status)->toBe('FAILED')
        ->and($task->error_code)->toBe('RATE_LIMIT')
        ->and((int) $task->retry_count)->toBe(1)
        ->and($task->retry_after)->not->toBeNull()
        ->and((int) $task->retry_of_task_id)->toBe((int) $task->id);

    // Worker pickup stand-in: the worker would only claim rows whose
    // retry_after has elapsed, so fast-forward it to the past.
    Capsule::table('tasks')->where('id', $task->id)->update([
        'retry_after' => date(Orchestrator::DB_TIMESTAMP_FORMAT, time() - 60),
    ]);

    // Orchestrator::retry() is the contract the worker relies on: it must
    // reset error fields, drop retry_after, drop retry_of_task_id, and put
    // the row back in QUEUED so the main QUEUED loop can claim it.
    $retried = $orch->retry($task->id);

    expect((string) $retried->status)->toBe('QUEUED')
        ->and($retried->retry_after)->toBeNull()
        ->and($retried->retry_of_task_id)->toBeNull()
        ->and($retried->error_code)->toBeNull()
        ->and($retried->failure_reason)->toBeNull()
        ->and((int) $retried->retry_count)->toBe(1); // preserved

    // Worker tick: QUEUED → RUNNING → tick → LLM recovers → COMPLETED.
    claimAndTick($orch, $retried->id);

    $retried->refresh();
    expect((string) $retried->status)->toBe('COMPLETED')
        ->and($retried->final_response)->toBe('Recovered.')
        ->and($retried->retry_after)->toBeNull()
        ->and($retried->error_code)->toBeNull();

    expect($callCount)->toBe(2);
});

it('end-to-end: retry budget exhaustion leaves the task FAILED without scheduling another retry', function (): void {
    [$agentId, $userId] = e2eRetrySeedAgent(retryAfterMinutes: 5, maxRetries: 2);

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount <= 3) {
            throw new LLMRateLimitException('429 rate limit');
        }
        // Budget is exhausted on the 3rd failure; the 4th call would mean
        // the scheduler wrongly re-armed the retry chain. Test fails loudly
        // if we reach this branch.
        PHPUnit\Framework\Assert::fail("LLM should not be called a 4th time (callCount={$callCount})");
    });

    $orch = e2eRetryMakeOrchestrator($mock);
    $task = e2eRetryCreateRunningTask($agentId, $userId, 'exhaust the budget');

    // Tick 1 — within budget, retry scheduled. retry_count goes 0 → 1.
    try {
        $orch->tick($task->id);
    } catch (LLMRateLimitException) {
        // expected
    }

    $task->refresh();
    expect((string) $task->status)->toBe('FAILED')
        ->and((int) $task->retry_count)->toBe(1)
        ->and($task->retry_after)->not->toBeNull();

    // Worker pickup + retry() + tick 2. retry_count goes 1 → 2; still
    // within max_retries=2 so the scheduler re-arms another retry.
    Capsule::table('tasks')->where('id', $task->id)->update([
        'retry_after' => date(Orchestrator::DB_TIMESTAMP_FORMAT, time() - 60),
    ]);
    $orch->retry($task->id);
    try {
        claimAndTick($orch, $task->id);
    } catch (LLMRateLimitException) {
        // expected
    }

    $task->refresh();
    expect((string) $task->status)->toBe('FAILED')
        ->and((int) $task->retry_count)->toBe(2)
        ->and($task->retry_after)->not->toBeNull();

    // Worker pickup + retry() + tick 3 — this is the budget-exhausted
    // failure. The scheduler bails before writing retry_count/retry_after,
    // so retry_count stays at 2 (not incremented) and retry_after MUST be
    // null so WorkerQueueProcessor stops re-arming the chain.
    Capsule::table('tasks')->where('id', $task->id)->update([
        'retry_after' => date(Orchestrator::DB_TIMESTAMP_FORMAT, time() - 60),
    ]);
    $orch->retry($task->id);
    try {
        claimAndTick($orch, $task->id);
    } catch (LLMRateLimitException) {
        // expected
    }

    $task->refresh();
    expect((string) $task->status)->toBe('FAILED')
        ->and((int) $task->retry_count)->toBe(2)
        ->and($task->retry_after)->toBeNull()
        ->and($task->retry_of_task_id)->toBeNull();

    expect($callCount)->toBe(3);
});

it('end-to-end: retry preserves pre-existing history and the next tick appends the recovery assistant row', function (): void {
    [$agentId, $userId] = e2eRetrySeedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            throw new LLMRateLimitException('429 rate limit');
        }
        return new LLMResponse('Done.', [], 5, 3, 'cmp_retry_history');
    });

    $orch = e2eRetryMakeOrchestrator($mock);
    $task = e2eRetryCreateRunningTask($agentId, $userId, 'history preservation');

    // Sanity: the seed helper pre-seeds a 'user' history row.
    $userRowsBefore = TaskHistory::where('task_id', $task->id)
        ->where('role', 'user')->count();
    expect($userRowsBefore)->toBe(1);

    // Tick 1 fails — handleTickFailure marks FAILED and re-arms retry, but
    // does NOT append an assistant row (the LLM never returned a response
    // to record). The 'user' row remains the only row.
    try {
        $orch->tick($task->id);
    } catch (LLMRateLimitException) {
        // expected
    }

    $rowsAfterFailure = TaskHistory::where('task_id', $task->id)->orderBy('sequence')->get();
    expect($rowsAfterFailure->where('role', 'user')->count())->toBe(1)
        ->and($rowsAfterFailure->where('role', 'assistant')->count())->toBe(0);

    $userRowIdsBefore = TaskHistory::where('task_id', $task->id)
        ->where('role', 'user')->pluck('id')->all();

    // retry() must NOT rewrite history. The pre-existing user row is
    // intact (same id, same content), and no assistant rows are created
    // by retry() itself — only the next tick's LLM response writes one.
    $orch->retry($task->id);

    $rowsAfterRetry = TaskHistory::where('task_id', $task->id)->orderBy('sequence')->get();
    $userRowIdsAfter = $rowsAfterRetry->where('role', 'user')->pluck('id')->all();

    expect($userRowIdsAfter)->toBe($userRowIdsBefore)
        ->and($rowsAfterRetry->where('role', 'assistant')->count())->toBe(0);

    // The recovery tick succeeds → orchestrator writes the assistant row.
    claimAndTick($orch, $task->id);

    $rowsAfterRecovery = TaskHistory::where('task_id', $task->id)->orderBy('sequence')->get();
    $userRows = $rowsAfterRecovery->where('role', 'user');
    $assistantRows = $rowsAfterRecovery->where('role', 'assistant');

    expect($userRows->count())->toBe(1)
        ->and($assistantRows->count())->toBe(1)
        ->and($assistantRows->first()->content)->toBe('Done.');

    // The pre-retry user row id is still present (not rewritten).
    expect($userRows->pluck('id')->all())->toBe($userRowIdsBefore);
});

it('end-to-end: retry is not scheduled for non-retryable codes (BAD_REQUEST)', function (): void {
    [$agentId, $userId] = e2eRetrySeedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        // The ErrorClassifier maps an LLMProviderException whose message
        // contains "400" to BAD_REQUEST, which is NOT in
        // RetryScheduler::RETRYABLE_ERROR_CODES — so no auto-retry.
        throw new LLMProviderException('Provider API error 400: malformed request');
    });

    $orch = e2eRetryMakeOrchestrator($mock);
    $task = e2eRetryCreateRunningTask($agentId, $userId, 'bad request no retry');

    try {
        $orch->tick($task->id);
    } catch (LLMProviderException) {
        // expected
    }

    $task->refresh();
    expect((string) $task->status)->toBe('FAILED')
        ->and((string) $task->error_code)->toBe('BAD_REQUEST')
        // Crucial assertion: retry_after stays NULL because the error
        // code is not in RETRYABLE_ERROR_CODES. WorkerQueueProcessor's
        // claim query (`retry_after IS NOT NULL`) won't pick this row
        // up, so the chain is correctly dead.
        ->and($task->retry_after)->toBeNull()
        ->and((int) $task->retry_count)->toBe(0);

    expect($callCount)->toBe(1);
});

it('end-to-end: retry IS scheduled for retryable codes (RATE_LIMIT) when policy is configured', function (): void {
    [$agentId, $userId] = e2eRetrySeedAgent();

    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        throw new LLMRateLimitException('429 rate limit');
    });

    $orch = e2eRetryMakeOrchestrator($mock);
    $task = e2eRetryCreateRunningTask($agentId, $userId, 'rate limit schedules retry');

    try {
        $orch->tick($task->id);
    } catch (LLMRateLimitException) {
        // expected
    }

    $task->refresh();
    expect((string) $task->status)->toBe('FAILED')
        ->and((string) $task->error_code)->toBe('RATE_LIMIT')
        ->and($task->retry_after)->not->toBeNull()
        ->and((int) $task->retry_count)->toBe(1);

    expect($callCount)->toBe(1);
});

it('end-to-end: full chain via Orchestrator::retry → claimAndTick hits every legal state transition', function (): void {
    [$agentId, $userId] = e2eRetrySeedAgent();

    // Realistic worker flow:
    //   RUNNING → (tick fails) → FAILED (retry_after=now+5min)
    //   → (worker waits, then re-arms by retry()) → QUEUED
    //   → (worker claimAndTick) → RUNNING → (tick succeeds) → COMPLETED
    //
    // Every transition must land in a legal state; no illegal jumps
    // (e.g. FAILED → COMPLETED without a tick, or retry() running on
    // a non-FAILED task).
    $callCount = 0;
    $mock = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            throw new LLMRateLimitException('429 rate limit');
        }
        return new LLMResponse('Worker picked me up.', [], 5, 3, 'cmp_worker_pickup');
    });

    $orch = e2eRetryMakeOrchestrator($mock);
    $task = e2eRetryCreateRunningTask($agentId, $userId, 'realistic worker chain');

    $observed = [];

    // 1. RUNNING (created above) → tick() throws → status FAILED + retry_after set
    try {
        $orch->tick($task->id);
    } catch (LLMRateLimitException) {
        // expected
    }
    $task->refresh();
    $observed[] = $task->status;
    expect($observed[0])->toBe('FAILED');

    // 2. Worker fast-forwards retry_after to the past, then calls retry()
    Capsule::table('tasks')->where('id', $task->id)->update([
        'retry_after' => date(Orchestrator::DB_TIMESTAMP_FORMAT, time() - 60),
    ]);

    $retried = $orch->retry($task->id);
    expect((string) $retried->status)->toBe('QUEUED')
        ->and($retried->retry_after)->toBeNull()
        ->and($retried->retry_of_task_id)->toBeNull()
        ->and($retried->error_code)->toBeNull()
        ->and($retried->failure_reason)->toBeNull();
    $observed[] = $retried->status;

    // 3. claimAndTick: QUEUED → RUNNING → tick → COMPLETED
    claimAndTick($orch, $retried->id);
    $retried->refresh();
    $observed[] = $retried->status;

    // Final state must be COMPLETED; trajectory must hit every legal
    // hop (FAILED → QUEUED → COMPLETED) without skipping.
    expect($observed)->toBe(['FAILED', 'QUEUED', 'COMPLETED']);
    expect($callCount)->toBe(2);
});
