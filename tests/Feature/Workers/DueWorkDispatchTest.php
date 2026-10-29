<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Console\Commands\WorkerRunCommand;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Console\Worker\WorkerReaper;
use Spora\Core\Config;
use Spora\Core\Database;
use Spora\Core\Paths;
use Spora\Http\WorkerController;
use Spora\Scheduling\DueWork;
use Spora\Scheduling\DueWorkDispatcher;
use Spora\Scheduling\DueWorkProviderRegistry;
use Spora\Services\DbRateLimiter;
use Spora\Services\HousekeepingLock;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Unit\Scheduling\SecondStubDueWorkProvider;
use Tests\Unit\Scheduling\StubDueWorkProvider;

/**
 * The two dispatch points, and the scope each one hands the provider.
 *
 * Server mode has no caller, so it acts for the whole install — the same
 * scope its scheduled-run claim has, which is not scoped at all. Client mode
 * has a caller, so it acts for the principals that caller may act for.
 */
defined('DUE_WORK_TEST_PASSWORD') || define('DUE_WORK_TEST_PASSWORD', 'Password1!');

/**
 * A server worker wired to the given dispatcher.
 *
 * The container is a mock because the real one would bring the whole graph
 * with it; what matters here is that the command resolves the dispatcher and
 * hands it the install's principals.
 */
function makeDueWorkWorkerCommand(DueWorkDispatcher $dispatcher): WorkerRunCommand
{
    Database::resetBootState();
    $db = new Database(new Config(['SPORA_DB_DRIVER' => 'sqlite', 'SPORA_DB_PATH' => ':memory:']));
    $db->boot();

    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $notificationService = Mockery::mock(NotificationService::class);

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $registry = new SettingsRegistry(new Paths(BASE_PATH));

    /** @var Mockery\MockInterface&ContainerInterface $container */
    $container = Mockery::mock(ContainerInterface::class)->shouldIgnoreMissing();
    $container->allows('get')->with(Config::class)->andReturn(new Config(['SPORA_WORKER_STALE_MINUTES' => 60]));
    $container->allows('get')->with(SettingsRegistry::class)->andReturn($registry);
    $container->allows('get')->with(SettingsRepository::class)
        ->andReturn(new SettingsRepository(
            new Spora\Core\SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            $registry,
        ));
    $container->shouldReceive('get')->with(WorkerReaper::class)->andReturn(
        new WorkerReaper(new NullLogger(), $notificationService, null),
    );
    $container->shouldReceive('has')->with(DueWorkDispatcher::class)->andReturnTrue();
    $container->shouldReceive('get')->with(DueWorkDispatcher::class)->andReturn($dispatcher);

    return new WorkerRunCommand(
        $db,
        $orchestrator,
        new NullLogger(),
        $container,
        $mercure,
        $notificationService,
        new Paths(BASE_PATH),
        WorkerRuntimeMode::Server,
    );
}

function makeDueWorkHousekeepingController(DueWorkDispatcher $dispatcher): WorkerController
{
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);
    $notificationService = Mockery::mock(NotificationService::class);

    $scheduledProcessor = new ScheduledRunProcessor(
        $orchestrator,
        new NullLogger(),
        $mercure,
        $notificationService,
    );

    return new WorkerController(
        bootAuthLayer(),
        WorkerRuntimeMode::Client,
        new DbRateLimiter(),
        new HousekeepingLock(),
        new WorkerReaper(new NullLogger(), $notificationService),
        $scheduledProcessor,
        60,
        600,
        $dispatcher,
        new NullLogger(),
    );
}

it('the server worker hands the provider every principal in the install', function (): void {
    $provider = new StubDueWorkProvider();
    $dispatcher = new DueWorkDispatcher(new DueWorkProviderRegistry([$provider]), new NullLogger());

    $command = makeDueWorkWorkerCommand($dispatcher);

    // Register a user inside the command's own database, then materialise
    // the principal row the provider's work would belong to.
    $authService = bootAuthLayer();
    $userId = $authService->register('due-work-server@example.com', DUE_WORK_TEST_PASSWORD, 'DueWorkServer');
    $principalId = createUserPrincipalPublic($userId);

    $input = new ArrayInput(['--once' => true], $command->getDefinition());
    (new ReflectionMethod($command, 'execute'))->invoke($command, $input, new NullOutput());

    expect($provider->claims)->toBe([[$principalId]]);
});

it('the server worker executes the work the provider handed back', function (): void {
    $provider = new StubDueWorkProvider();
    $dispatcher = new DueWorkDispatcher(new DueWorkProviderRegistry([$provider]), new NullLogger());

    $command = makeDueWorkWorkerCommand($dispatcher);

    $authService = bootAuthLayer();
    $userId = $authService->register('due-work-server-exec@example.com', DUE_WORK_TEST_PASSWORD, 'DueWorkServerExec');
    $principalId = createUserPrincipalPublic($userId);

    $provider->work = [new DueWork(
        provider: $provider::class,
        principalId: $principalId,
        payload: ['id' => 'slot-1'],
        dueAt: '2026-01-01 10:00:00',
    )];

    $input = new ArrayInput(['--once' => true], $command->getDefinition());
    (new ReflectionMethod($command, 'execute'))->invoke($command, $input, new NullOutput());

    expect($provider->executed)->toHaveCount(1)
        ->and((string) $provider->executed[0]->payload['id'])->toBe('slot-1');
});

it('the housekeeping call hands the provider the principals the caller may act for', function (): void {
    $provider = new StubDueWorkProvider();
    $dispatcher = new DueWorkDispatcher(new DueWorkProviderRegistry([$provider]), new NullLogger());

    $authService = bootAuthLayer();
    $userId = $authService->register('due-work-client@example.com', DUE_WORK_TEST_PASSWORD, 'DueWorkClient');
    simulateLoggedInSession($userId, 'due-work-client@example.com');
    $principalId = createUserPrincipalPublic($userId);

    $controller = makeDueWorkHousekeepingController($dispatcher);
    $response = $controller->housekeeping();

    expect($response->getStatusCode())->toBe(200);
    expect($provider->claims)->toBe([[$principalId]]);
});

it('the housekeeping call does not reach work belonging to a principal the caller cannot act for', function (): void {
    $provider = new StubDueWorkProvider();
    $dispatcher = new DueWorkDispatcher(new DueWorkProviderRegistry([$provider]), new NullLogger());

    $authService = bootAuthLayer();
    $userId = $authService->register('due-work-client-scope@example.com', DUE_WORK_TEST_PASSWORD, 'DueWorkClientScope');
    simulateLoggedInSession($userId, 'due-work-client-scope@example.com');
    $principalId = createUserPrincipalPublic($userId);

    $foreignPrincipalId = createUserPrincipalPublic(9999);

    $provider->work = [new DueWork(
        provider: $provider::class,
        principalId: $foreignPrincipalId,
        payload: ['id' => 'foreign'],
        dueAt: '2026-01-01 10:00:00',
    )];

    $controller = makeDueWorkHousekeepingController($dispatcher);
    $response = $controller->housekeeping();

    expect($response->getStatusCode())->toBe(200);
    expect($provider->claims)->toBe([[$principalId]])
        ->and($provider->executed)->toBe([]);
});

it('a provider that throws does not fail the housekeeping response', function (): void {
    $broken = new StubDueWorkProvider();
    $broken->throwOnClaim = true;

    $working = new SecondStubDueWorkProvider();
    $dispatcher = new DueWorkDispatcher(new DueWorkProviderRegistry([$broken, $working]), new NullLogger());

    $authService = bootAuthLayer();
    $userId = $authService->register('due-work-broken@example.com', DUE_WORK_TEST_PASSWORD, 'DueWorkBroken');
    simulateLoggedInSession($userId, 'due-work-broken@example.com');
    createUserPrincipalPublic($userId);

    $controller = makeDueWorkHousekeepingController($dispatcher);
    $response = $controller->housekeeping();

    expect($response->getStatusCode())->toBe(200);
    // The provider after the broken one was still consulted.
    expect($working->claims)->toHaveCount(1);
});

it('a provider that throws does not fail the worker run', function (): void {
    $broken = new StubDueWorkProvider();
    $broken->throwOnClaim = true;

    $working = new SecondStubDueWorkProvider();
    $dispatcher = new DueWorkDispatcher(new DueWorkProviderRegistry([$broken, $working]), new NullLogger());

    $command = makeDueWorkWorkerCommand($dispatcher);

    $authService = bootAuthLayer();
    $userId = $authService->register('due-work-broken-server@example.com', DUE_WORK_TEST_PASSWORD, 'DueWorkBrokenServer');
    createUserPrincipalPublic($userId);

    $input = new ArrayInput(['--once' => true], $command->getDefinition());
    $exitCode = (new ReflectionMethod($command, 'execute'))->invoke($command, $input, new NullOutput());

    expect($exitCode)->toBe(0)
        ->and($working->claims)->toHaveCount(1);
});
