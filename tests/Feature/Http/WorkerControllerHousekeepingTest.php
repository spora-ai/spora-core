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
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
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

describe('WorkerController::housekeeping (client-worker mode)', function (): void {

    it('returns 404 when worker_runtime_mode is Server', function (): void {
        $harness = makeHousekeepingController(WorkerRuntimeMode::Server);
        $userId = $harness['auth']->register('hk-server@example.com', HOUSEKEEPING_TEST_PASSWORD, 'HKServer');
        simulateLoggedInSession($userId, 'hk-server@example.com');

        $response = $harness['controller']->housekeeping();

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

        $response = $harness['controller']->housekeeping();

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

        $response = $harness['controller']->housekeeping();

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

        expect(fn() => $harness['controller']->housekeeping())
            ->toThrow(Spora\Http\Exceptions\TooManyRequestsException::class);
    });

    it('rate-limits the 7th call: 5 pre-seeded + 2 calls in a tight loop', function (): void {
        // Counterpart to the pre-seed test above: drive the rate
        // limiter's increment path once (the first call inserts and
        // takes the count from 5 → 6), then assert the next call hits
        // the cap. Without the increment path, the pre-seed test only
        // exercises the count >= max branch. The hit_at column is at
        // second precision so a tighter loop would PK-collide on
        // (key, hit_at) and silently fail-open.
        $harness = makeHousekeepingController(WorkerRuntimeMode::Client);
        $userId = $harness['auth']->register('hk-loop@example.com', HOUSEKEEPING_TEST_PASSWORD, 'HKLoop');
        simulateLoggedInSession($userId, 'hk-loop@example.com');
        $harness['scheduledProcessor']->lastProcessed = 0;

        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'key'    => 'housekeeping:' . $userId,
                'hit_at' => date(HOUSEKEEPING_DT, time() - 30 - $i),
            ];
        }
        Capsule::table('ratelimit_hits')->insert($rows);
        unset($rows);

        // 6th successful call: increment path (count 5 → 6).
        $response = $harness['controller']->housekeeping();
        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        // 7th call: cap reached, throws TooManyRequestsException.
        expect(fn() => $harness['controller']->housekeeping())
            ->toThrow(Spora\Http\Exceptions\TooManyRequestsException::class);
    });

    it('returns 500 with HOUSEKEEPING_FAILED when the reaper throws mid-flight', function (): void {
        // Force the catch-block path: any Throwable from inside the try
        // (reaper, scheduled-run processor, etc.) must surface as a
        // HOUSEKEEPING_FAILED envelope so the SPA can distinguish a
        // server-side crash from a successful 200 with reaped=0. Drop
        // the tasks table — WorkerReaper's first query then throws, the
        // controller catches Throwable, and the JSON envelope preserves
        // the original error message for debugging. The lock must still
        // be released in the finally block.
        $harness = makeHousekeepingController(WorkerRuntimeMode::Client);
        $userId = $harness['auth']->register('hk-500@example.com', HOUSEKEEPING_TEST_PASSWORD, 'HK500');
        simulateLoggedInSession($userId, 'hk-500@example.com');

        // The reaper's first query targets `tasks`. Drop it to force the
        // throw. Foreign keys must be off — reaper doesn't reference any
        // child table, but other tables do.
        $conn = Capsule::connection();
        $conn->statement('PRAGMA foreign_keys = OFF');
        Capsule::schema()->drop('tasks');

        $response = $harness['controller']->housekeeping();

        $conn->statement('PRAGMA foreign_keys = ON');

        expect($response->getStatusCode())->toBe(Response::HTTP_INTERNAL_SERVER_ERROR);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['error']['code'])->toBe('HOUSEKEEPING_FAILED')
            ->and($body['error']['message'])->toBeString();

        // The finally clause must run regardless — the lock is released
        // back to "in the past" so the next caller can take it.
        $lock = Capsule::table('worker_housekeeping_locks')->where('id', 1)->first();
        if ($lock !== null) {
            expect(strtotime($lock->claimed_until . ' UTC'))->toBeLessThanOrEqual(time());
        }
    });
});
