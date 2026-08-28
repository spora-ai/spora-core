<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Auth\AuthService;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Console\Worker\WorkerReaper;
use Spora\Http\WorkerController;
use Spora\Services\DbRateLimiter;
use Spora\Services\HousekeepingLock;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

defined('HOUSEKEEPING_TEST_PASSWORD') || define('HOUSEKEEPING_TEST_PASSWORD', 'Password1!');
const HOUSEKEEPING_DT = 'Y-m-d H:i:s';

/**
 * Build a WorkerController with the reaper mocked (it has no public surface
 * we want to drive here) and a real ScheduledRunProcessor that talks to a
 * mocked orchestrator + mercure (the processor is final, so we mock its
 * collaborators, not the processor itself).
 *
 * @return array{controller: WorkerController, auth: AuthService, scheduledProcessor: ScheduledRunProcessor, reaper: WorkerReaper, lock: HousekeepingLock}
 */
function makeHousekeepingController(WorkerRuntimeMode $runtimeMode): array
{
    $authService = bootAuthLayer();

    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);
    $notificationService = Mockery::mock(NotificationService::class);
    $notificationService->allows('notifyScheduledRunCompleted')->andReturnNull();
    $notificationService->allows('sendEmailForScheduledRun')->andReturnNull();

    $scheduledProcessor = new ScheduledRunProcessor(
        $orchestrator,
        new NullLogger(),
        $mercure,
        $notificationService,
    );
    $scheduledProcessor->lastProcessed = 0;

    $reaper = new WorkerReaper(new NullLogger(), $notificationService);

    $lock = new HousekeepingLock();

    $controller = new WorkerController(
        $authService,
        $runtimeMode,
        new DbRateLimiter(),
        $lock,
        $reaper,
        $scheduledProcessor,
        60,
        600,
    );

    return [
        'controller'  => $controller,
        'auth'        => $authService,
        'scheduledProcessor' => $scheduledProcessor,
        'reaper'      => $reaper,
        'lock'        => $lock,
    ];
}

function buildHousekeepingRequest(): Request
{
    return new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], '');
}

describe('WorkerController::housekeeping (client-worker mode)', function (): void {

    it('returns 404 when worker_runtime_mode is Server', function (): void {
        $harness = makeHousekeepingController(WorkerRuntimeMode::Server);
        $userId = $harness['auth']->register('hk-server@example.com', HOUSEKEEPING_TEST_PASSWORD, 'HKServer');
        simulateLoggedInSession($userId, 'hk-server@example.com');

        $response = $harness['controller']->housekeeping(buildHousekeepingRequest());

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    it('returns 204 when another call already holds the lock', function (): void {
        $harness = makeHousekeepingController(WorkerRuntimeMode::Client);
        $userId = $harness['auth']->register('hk-lock@example.com', HOUSEKEEPING_TEST_PASSWORD, 'HKLock');
        simulateLoggedInSession($userId, 'hk-lock@example.com');

        // Pre-insert a lock that's still active (claimed_until in the future).
        Capsule::table('worker_housekeeping_locks')->insertOrIgnore([
            'id'            => 1,
            'claimed_until' => date(HOUSEKEEPING_DT, time() + 30),
            'claimed_by'    => 999,
        ]);

        $response = $harness['controller']->housekeeping(buildHousekeepingRequest());

        expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT);
        expect((string) $response->getContent())->toBe('');
    });

    it('returns 200 with reaped + scheduled_dispatched + ran_by counts on a fresh call', function (): void {
        $harness = makeHousekeepingController(WorkerRuntimeMode::Client);
        $userId = $harness['auth']->register('hk-ok@example.com', HOUSEKEEPING_TEST_PASSWORD, 'HKOk');
        simulateLoggedInSession($userId, 'hk-ok@example.com');

        // Pre-set the processor's lastProcessed so the controller reports
        // the scheduled-run dispatch count without needing a real schedule.
        $harness['scheduledProcessor']->lastProcessed = 1;

        $response = $harness['controller']->housekeeping(buildHousekeepingRequest());

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode((string) $response->getContent(), true);
        expect($body)->toHaveKeys(['reaped', 'scheduled_dispatched', 'ran_by'])
            ->and($body['ran_by'])->toBe($userId)
            ->and($body['reaped'])->toBe(0)
            ->and($body['scheduled_dispatched'])->toBe(1);
    });

    it('rate-limits to 6 requests per minute per user', function (): void {
        // Plan: 6 req/min. The 7th call within the rolling 60s window
        // must throw TooManyRequestsException → 429 envelope. Pre-seed
        // 6 hits with distinct hit_at values (PK on (key, hit_at)) so
        // the rate limiter's count() returns 6 immediately on the next call.
        $harness = makeHousekeepingController(WorkerRuntimeMode::Client);
        $userId = $harness['auth']->register('hk-rl@example.com', HOUSEKEEPING_TEST_PASSWORD, 'HKRL');
        simulateLoggedInSession($userId, 'hk-rl@example.com');

        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = [
                'key'    => 'housekeeping:' . $userId,
                'hit_at' => date(HOUSEKEEPING_DT, time() - $i),
            ];
        }
        Capsule::table('ratelimit_hits')->insert($rows);
        unset($rows);

        expect(fn() => $harness['controller']->housekeeping(buildHousekeepingRequest()))
            ->toThrow(Spora\Http\Exceptions\TooManyRequestsException::class);
    });
});
