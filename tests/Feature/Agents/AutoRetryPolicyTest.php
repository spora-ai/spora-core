<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Console\Worker\WorkerQueueProcessor;
use Spora\Core\Paths;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The auto-retry policy, exercised through the production chain.
 *
 * A configured retry is the operator's answer to a provider that is briefly
 * unavailable. The chain that answers it is: the driver fails → the error is
 * classified → the retry is armed on the row → the worker claims it once the
 * wait has elapsed. Every link of that chain is covered elsewhere, but none
 * of them covers the two failures that break it.
 *
 * **A provider that cannot be reached.** A transport failure is not a
 * timeout and not an HTTP error — the request never got an answer. Symfony
 * models that as a `TransportException`, which carries the transport
 * interface but not the timeout one, so it lands outside every branch the
 * classifier had and falls through to UNKNOWN. UNKNOWN is not a retryable
 * code, so the retry never arms and the operator is left with a task that
 * says "please try again" and nothing that does.
 *
 * **A stamp that means different instants in the two processes that read
 * it.** The retry is armed wherever the failure happened — a `task:run`
 * child, an HTTP request — and claimed by the worker daemon, which pins UTC
 * before it compares a single timestamp. A host that is not UTC therefore
 * has two clocks in play, and the stamp has to name the same instant in
 * both. The tests below that cover this one arm the retry through a
 * rate limit, which was already retryable: otherwise the classification
 * gap would hide it, and a test that cannot fail proves nothing.
 */

defined('AUTO_RETRY_TEST_PASSWORD') || define('AUTO_RETRY_TEST_PASSWORD', 'Password1!');

/**
 * An agent configured to retry: `retry_after_minutes` delays each attempt,
 * `max_retries` bounds how many there are.
 *
 * @return array{0: int, 1: int} the agent id and the owning user id
 */
function autoRetrySeedAgent(int $retryAfterMinutes = 5, int $maxRetries = 3): array
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('auto-retry@example.com', AUTO_RETRY_TEST_PASSWORD, 'Auto Retry');

    $config = LLMDriverConfiguration::create([
        'principal_id'      => null,
        'name'              => 'Auto Retry Config',
        'driver_class'      => OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'        => createUserPrincipalPublic($userId),
        'name'                => 'Auto Retry Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'           => 10,
        'is_active'           => true,
        'retry_after_minutes' => $retryAfterMinutes,
        'max_retries'         => $maxRetries,
    ]);

    return [(int) $agent->id, $userId];
}

function autoRetryOrchestrator(
    LLMDriverInterface $driver,
    WorkerRuntimeMode $workerRuntimeMode = WorkerRuntimeMode::Server,
): Orchestrator {
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    return new Orchestrator($factory, new OrchestratorConfig(workerRuntimeMode: $workerRuntimeMode));
}

function autoRetryRunningTask(int $agentId, int $userId): Task
{
    return Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'reach the provider',
        'step_count'      => 0,
        'max_steps'       => 10,
        'retry_count'     => 0,
    ]);
}

/**
 * A worker that claims whatever retry is due.
 *
 * The orchestrator is a mock: these tests are about whether the worker
 * reaches the row the scheduler armed, not about what the orchestrator then
 * does with it.
 */
function autoRetryWorker(OrchestratorInterface $orchestrator): WorkerQueueProcessor
{
    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldIgnoreMissing();

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->shouldIgnoreMissing();

    return new WorkerQueueProcessor(
        orchestrator: $orchestrator,
        logger: new NullLogger(),
        mercure: $mercure,
        notificationService: $notification,
        paths: new Paths(BASE_PATH),
    );
}

/**
 * Run a tick that fails with the given exception, and return the task.
 *
 * The failure propagates out of the tick; the orchestrator has already
 * marked the task FAILED and armed the retry by then.
 */
function autoRetryFailedTask(
    int $agentId,
    int $userId,
    Throwable $failure,
): Task {
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andThrow($failure);

    $orchestrator = autoRetryOrchestrator($driver);
    $task = autoRetryRunningTask($agentId, $userId);

    try {
        $orchestrator->tick($task->id);
        Assert::fail('Expected the failure to propagate');
    } catch (Throwable $e) {
        if ($e !== $failure) {
            throw $e;
        }
    }

    return $task;
}

it('arms the configured retry when the provider cannot be reached', function (): void {
    [$agentId, $userId] = autoRetrySeedAgent();

    $task = autoRetryFailedTask(
        $agentId,
        $userId,
        new TransportException('Could not resolve host: api.minimax.io'),
    );

    $task->refresh();

    expect((string) $task->status)->toBe('FAILED')
        // A provider that cannot be reached is a transient failure, so the
        // retry policy has to accept it. UNKNOWN is not retryable, and it
        // left the operator with a task that said "please try again" and
        // nothing that did.
        ->and((string) $task->error_code)->toBe('GATEWAY_ERROR')
        ->and((int) $task->retry_count)->toBe(1)
        ->and($task->retry_after)->not->toBeNull()
        ->and((int) $task->retry_of_task_id)->toBe((int) $task->id);
});

it('does not arm a retry in client-worker mode', function (): void {
    // Only the server worker claims a scheduled retry — the retry queue
    // lives in its daemon. Arming one in client-worker mode would promise
    // a retry that never comes, and a parent waiting on that retry would
    // wait for it forever.
    [$agentId, $userId] = autoRetrySeedAgent();

    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andThrow(new LLMRateLimitException('429 rate limit'));

    $orchestrator = autoRetryOrchestrator($driver, WorkerRuntimeMode::Client);
    $task = autoRetryRunningTask($agentId, $userId);

    try {
        $orchestrator->tick($task->id);
        Assert::fail('Expected the failure to propagate');
    } catch (LLMRateLimitException) {
        // expected
    }

    $task->refresh();

    expect((string) $task->status)->toBe('FAILED')
        ->and((int) $task->retry_count)->toBe(0)
        ->and($task->retry_after)->toBeNull()
        ->and($task->retry_of_task_id)->toBeNull();
});

it('the real driver lets a connection error through as a retryable failure', function (): void {
    // The classification gap is only worth fixing if the production driver
    // actually produces the exception the classifier has to recognise. The
    // driver does not translate a transport failure — it escapes untouched.
    [$agentId, $userId] = autoRetrySeedAgent();

    $client = new MockHttpClient(static function (string $method, string $url): never {
        throw new TransportException('Recv failure: Connection reset by peer for "' . $url . '".');
    });

    $driver = new OpenAICompatibleDriver(
        apiKey: 'test-key',
        model: 'gpt-5.5',
        baseUrl: 'https://api.example.invalid/v1',
        httpClient: $client,
    );

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    $orchestrator = new Orchestrator($factory);
    $task = autoRetryRunningTask($agentId, $userId);

    try {
        $orchestrator->tick($task->id);
        Assert::fail('Expected the transport failure to propagate');
    } catch (TransportException) {
        // expected
    }

    $task->refresh();

    expect((string) $task->error_code)->toBe('GATEWAY_ERROR')
        ->and((int) $task->retry_count)->toBe(1)
        ->and($task->retry_after)->not->toBeNull();
});

it('stamps the retry at the configured delay in absolute time', function (): void {
    // A rate limit, so the retry is armed on every version of the chain
    // and this test is about the stamp alone.
    [$agentId, $userId] = autoRetrySeedAgent(retryAfterMinutes: 5);

    $previousTimezone = date_default_timezone_get();

    try {
        // A host that is not UTC. Europe/Berlin is one hour ahead in
        // winter and two in summer, so a local-time stamp is wrong by an
        // hour or more either way.
        date_default_timezone_set('Europe/Berlin');

        $task = autoRetryFailedTask($agentId, $userId, new LLMRateLimitException('429 rate limit'));
        $task->refresh();
    } finally {
        date_default_timezone_set($previousTimezone);
    }

    $stamped = new DateTimeImmutable((string) $task->retry_after, new DateTimeZone('UTC'));

    expect($stamped->getTimestamp())->toBeBetween(time() + 5 * 60 - 5, time() + 5 * 60 + 5);
});

it('the worker leaves the armed retry alone before the wait has elapsed', function (): void {
    [$agentId, $userId] = autoRetrySeedAgent();

    $previousTimezone = date_default_timezone_get();

    try {
        // The host's clock, where the retry is armed. Five hours behind the
        // one the worker compares against.
        date_default_timezone_set('Etc/GMT+5');

        $task = autoRetryFailedTask($agentId, $userId, new LLMRateLimitException('429 rate limit'));

        // The worker's clock. WorkerRunCommand pins UTC before the worker
        // compares a single timestamp.
        date_default_timezone_set('UTC');

        // The retry is armed for five minutes out. The worker must not take
        // it before then — the operator asked for a delay, not an instant
        // retry, and taking it early spends the retry budget on a failure
        // that has not had its chance yet.
        $workerOrchestrator = Mockery::mock(OrchestratorInterface::class);
        $workerOrchestrator->shouldNotReceive('retry');

        autoRetryWorker($workerOrchestrator)->processRetryQueue();
    } finally {
        date_default_timezone_set($previousTimezone);
    }
});

it('the worker claims the armed retry once the wait has elapsed', function (): void {
    // The whole chain, end to end: a provider that cannot be reached is
    // classified as transient, the retry is armed on the row, and the
    // worker picks that row up and hands it back to the orchestrator.
    [$agentId, $userId] = autoRetrySeedAgent();

    $task = autoRetryFailedTask(
        $agentId,
        $userId,
        new TransportException('Could not resolve host: api.minimax.io'),
    );

    // The wait has elapsed.
    Capsule::table('tasks')->where('id', $task->id)->update([
        'retry_after' => gmdate(Orchestrator::DB_TIMESTAMP_FORMAT, time() - 60),
    ]);

    $workerOrchestrator = Mockery::mock(OrchestratorInterface::class);
    $workerOrchestrator->shouldReceive('retry')->once()->with((int) $task->id)
        ->andReturnUsing(static fn(int $id) => Task::find($id));

    autoRetryWorker($workerOrchestrator)->processRetryQueue();
});
