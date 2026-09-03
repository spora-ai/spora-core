<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Agents\ErrorClassifier;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\RetryScheduler;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Auth\AuthService;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\TaskTickController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\DbRateLimiter;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;
use Spora\Services\ToolCallSerializer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

defined('TICK_TEST_PASSWORD') || define('TICK_TEST_PASSWORD', 'Password1!');
const TICK_DT = 'Y-m-d H:i:s';

/**
 * Build a TaskTickController wired to a mocked orchestrator + mercure.
 *
 * @param  WorkerRuntimeMode  $runtimeMode
 * @param  ?LLMDriverInterface  $llm
 * @return array{controller: TaskTickController, orchestrator: Orchestrator, mercure: MercurePublisherInterface, userId: int, agentId: int}
 */
function makeTickController(WorkerRuntimeMode $runtimeMode, ?LLMDriverInterface $llm = null): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('tick@example.com', TICK_TEST_PASSWORD, 'Tick');
    simulateLoggedInSession($userId, 'tick@example.com');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'          => 'Tick Test Global',
        'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'      => json_encode(['api_key' => 'test']),
        'is_global'     => true,
        'is_default'    => true,
        'context_window' => 128000,
        'max_tokens_output' => 4096,
    ]);
    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'                 => 'Tick Test Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $driver = $llm ?? tap(Mockery::mock(LLMDriverInterface::class), static function (LLMDriverInterface $mock): void {
        $mock->allows('complete')->andReturn(new LLMResponse('Done.', [], 5, 3, 'cmp_tick'));
        $mock->allows('getProviderName')->andReturn('mock');
        $mock->allows('getModelName')->andReturn('mock-model');
    });
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);
    $orchestrator = new Orchestrator($factory, new OrchestratorConfig(logger: new NullLogger()));

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $service = new TaskService($orchestrator, $mercure, new ToolCallSerializer([]));

    $controller = new TaskTickController(
        $authService,
        $service,
        $runtimeMode,
        new DbRateLimiter(),
        $mercure,
        $orchestrator,
        new ErrorClassifier(),
        new RetryScheduler(),
        null,
        new NullLogger(),
        600,
        new Spora\Services\PrincipalResolver(),
    );

    return [
        'controller'  => $controller,
        'orchestrator' => $orchestrator,
        'mercure'     => $mercure,
        'userId'      => $userId,
        'agentId'     => $agent->id,
    ];
}

/**
 * Create a task owned by $userId against $agentId in the given source status.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedTickTask(int $userId, int $agentId, array $overrides = []): Task
{
    return Task::create(array_merge([
        'agent_id'    => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'      => 'QUEUED',
        'user_prompt' => 'tick target',
        'max_steps'   => 10,
        'step_count'  => 0,
    ], $overrides));
}

/**
 * Build a TaskTickController with custom auth + collaborators. Returns the
 * pieces the tests need (service, orchestrator, mercure) alongside the
 * controller so callers can hand-roll a sibling controller for the
 * not-owned / Mercure-publish tests without re-deriving the wiring.
 *
 * @return array{controller: TaskTickController, orchestrator: Orchestrator, mercure: MercurePublisherInterface, service: TaskService, authService: AuthService}
 */
function makeRawTickController(
    AuthService $authService,
    WorkerRuntimeMode $runtimeMode,
    Orchestrator $orchestrator,
    MercurePublisherInterface $mercure,
): array {
    $service = new TaskService($orchestrator, $mercure, new ToolCallSerializer([]), new Spora\Services\PrincipalResolver());

    $controller = new TaskTickController(
        $authService,
        $service,
        $runtimeMode,
        new DbRateLimiter(),
        $mercure,
        $orchestrator,
        new ErrorClassifier(),
        new RetryScheduler(),
        null,
        new NullLogger(),
        600,
        new Spora\Services\PrincipalResolver(),
    );

    return [
        'controller'  => $controller,
        'orchestrator' => $orchestrator,
        'mercure'     => $mercure,
        'service'     => $service,
        'authService' => $authService,
    ];
}

function buildTickRequest(int $taskId): Request
{
    $req = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], '');
    $req->attributes->set('taskId', $taskId);

    return $req;
}

describe('TaskController::tick (client-worker mode)', function (): void {

    it('returns 404 when worker_runtime_mode is Server', function (): void {
        $harness = makeTickController(WorkerRuntimeMode::Server);
        $task = seedTickTask($harness['userId'], $harness['agentId']);

        $response = $harness['controller']->tick(buildTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $task->refresh();
        // Server mode must NOT claim the row.
        expect($task->status)->toBe('QUEUED');
    });

    it('returns 404 when caller does not own the task (not 403)', function (): void {
        // Existence-hiding per plan finding #6 — a 403 would leak that the
        // task exists at all.
        $harness = makeTickController(WorkerRuntimeMode::Client);
        $task = seedTickTask($harness['userId'], $harness['agentId']);

        // Different user logs in.
        $otherAuth = bootAuthLayer();
        $otherId = $otherAuth->register('tick-other@example.com', TICK_TEST_PASSWORD, 'Other');
        simulateLoggedInSession($otherId, 'tick-other@example.com');

        $otherService = new TaskService($harness['orchestrator'], $harness['mercure'], new ToolCallSerializer([]), new Spora\Services\PrincipalResolver());
        $controller = new TaskTickController(
            $otherAuth,
            $otherService,
            WorkerRuntimeMode::Client,
            new DbRateLimiter(),
            $harness['mercure'],
            $harness['orchestrator'],
            new ErrorClassifier(),
            new RetryScheduler(),
            null,
            new NullLogger(),
            600,
            new Spora\Services\PrincipalResolver(),
        );

        $response = $controller->tick(buildTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $task->refresh();
        expect($task->status)->toBe('QUEUED');
    });

    it('returns 409 TICK_LOST_RACE when the claim fails', function (): void {
        // The CAS-claim path: a row already in RUNNING (e.g. another tab
        // claimed it 100ms ago) cannot be re-claimed — the caller gets 409
        // so the browser can back off.
        $harness = makeTickController(WorkerRuntimeMode::Client);
        $task = seedTickTask($harness['userId'], $harness['agentId'], ['status' => 'RUNNING']);

        $response = $harness['controller']->tick(buildTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('TICK_ALREADY_RUNNING');
    });

    it('publishes Mercure QUEUED→RUNNING before the tick', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('tick-pub@example.com', TICK_TEST_PASSWORD, 'TickPub');
        simulateLoggedInSession($userId, 'tick-pub@example.com');

        $config = LLMDriverConfiguration::create([
            'principal_id' => null,
            'name'          => 'Tick Pub Global',
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);
        $agent = Agent::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'name'                 => 'Tick Pub Agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);
        $task = seedTickTask($userId, $agent->id);

        $driver = Mockery::mock(LLMDriverInterface::class);
        $driver->allows('complete')->andReturn(new LLMResponse('Done.', [], 5, 3, 'cmp_pub'));
        $driver->allows('getProviderName')->andReturn('mock');
        $driver->allows('getModelName')->andReturn('mock-model');
        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($driver);
        $orchestrator = new Orchestrator($factory, new OrchestratorConfig(logger: new NullLogger()));

        // Mercure publishes RUNNING once BEFORE the LLM call (UI flips
        // status immediately) and may also publish the terminal status —
        // only the QUEUED→RUNNING assertion matters here.
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->shouldReceive('publishForPrincipal')
            ->with($task->id, $task->principalOwnerId(), ['task_id' => $task->id, 'status' => 'RUNNING'])
            ->atLeast()
            ->once()
            ->andReturn(true);
        $mercure->shouldReceive('publishForPrincipal')->andReturn(true);

        $service = new TaskService($orchestrator, $mercure, new ToolCallSerializer([]), new Spora\Services\PrincipalResolver());
        $controller = new TaskTickController(
            $authService,
            $service,
            WorkerRuntimeMode::Client,
            new DbRateLimiter(),
            $mercure,
            $orchestrator,
            new ErrorClassifier(),
            new RetryScheduler(),
            null,
            new NullLogger(),
            600,
            new Spora\Services\PrincipalResolver(),
        );

        $response = $controller->tick(buildTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    it('flips to FAILED on tick exception and clears the lease', function (): void {
        // Mirrors TaskController::tick's catch branch: any throw from the
        // orchestrator flips the row to FAILED + clears lease + publishes
        // Mercure FAILED. Without this the operator's UI would sit on a
        // phantom RUNNING.
        $authService = bootAuthLayer();
        $userId = $authService->register('tick-fail@example.com', TICK_TEST_PASSWORD, 'TickFail');
        simulateLoggedInSession($userId, 'tick-fail@example.com');

        $config = LLMDriverConfiguration::create([
            'principal_id' => null,
            'name'          => 'Tick Fail Global',
            'driver_class'  => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'      => json_encode(['api_key' => 'test']),
            'is_global'     => true,
            'is_default'    => true,
            'context_window' => 128000,
            'max_tokens_output' => 4096,
        ]);
        $agent = Agent::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'name'                 => 'Tick Fail Agent',
            'llm_driver_config_id' => $config->id,
            'max_steps'            => 10,
            'is_active'            => true,
        ]);
        $task = seedTickTask($userId, $agent->id);

        $throwingDriver = Mockery::mock(LLMDriverInterface::class);
        $throwingDriver->allows('complete')->andThrow(new RuntimeException('LLM down'));
        $throwingDriver->allows('getProviderName')->andReturn('mock');
        $throwingDriver->allows('getModelName')->andReturn('mock-model');
        $factory = Mockery::mock(DriverFactory::class);
        $factory->allows('makeFromAgent')->andReturn($throwingDriver);
        $orchestrator = new Orchestrator($factory, new OrchestratorConfig(logger: new NullLogger()));

        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
        $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        $mercure->allows('publish')->andReturn(true);

        $service = new TaskService($orchestrator, $mercure, new ToolCallSerializer([]), new Spora\Services\PrincipalResolver());
        $controller = new TaskTickController(
            $authService,
            $service,
            WorkerRuntimeMode::Client,
            new DbRateLimiter(),
            $mercure,
            $orchestrator,
            new ErrorClassifier(),
            new RetryScheduler(),
            null,
            new NullLogger(),
            600,
            new Spora\Services\PrincipalResolver(),
        );

        $response = $controller->tick(buildTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        $task->refresh();
        expect($task->status)->toBe('FAILED')
            ->and($task->error_code)->toBe('UNKNOWN')
            ->and($task->lease_owner)->toBeNull()
            ->and($task->lease_expires_at)->toBeNull();
    });

    it('clears the lease once the row reaches a terminal status', function (): void {
        // After a successful tick that lands on COMPLETED, the lease must
        // be cleared — otherwise the reaper could flip an actually-finished
        // task to FAILED on the next housekeeping pass.
        $harness = makeTickController(WorkerRuntimeMode::Client);
        $task = seedTickTask($harness['userId'], $harness['agentId']);

        $response = $harness['controller']->tick(buildTickRequest($task->id));

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $task->refresh();
        expect($task->status)->toBe('COMPLETED')
            ->and($task->lease_owner)->toBeNull()
            ->and($task->lease_expires_at)->toBeNull();
    });

    it('returns 429 when the per-user rate limit is exceeded', function (): void {
        // DbRateLimiter's bucket is per-key — fill 'tick:<userId>' with 60
        // hits inside the rolling 60s window. The next call returns false
        // and the controller throws TooManyRequestsException → 429 envelope
        // (Kernel::mapKnownExceptionToResponse converts it to a JSON body
        // with status 429; we assert the throw here at the controller level).
        // hit_at values must be unique across rows (PK on (key, hit_at)).
        $harness = makeTickController(WorkerRuntimeMode::Client);

        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = [
                'key'    => 'tick:' . $harness['userId'],
                'hit_at' => date(TICK_DT, time() - $i),
            ];
        }
        Illuminate\Database\Capsule\Manager::table('ratelimit_hits')->insert($rows);
        unset($rows);

        $task = seedTickTask($harness['userId'], $harness['agentId']);

        expect(fn() => $harness['controller']->tick(buildTickRequest($task->id)))
            ->toThrow(Spora\Http\Exceptions\TooManyRequestsException::class);
    });
});
