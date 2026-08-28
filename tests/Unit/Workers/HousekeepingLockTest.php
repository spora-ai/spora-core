<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\HousekeepingLock;

defined('HOUSEKEEPING_LOCK_PASSWORD') || define('HOUSEKEEPING_LOCK_PASSWORD', 'Password1!');
const HOUSEKEEPING_LOCK_DT = 'Y-m-d H:i:s';

describe('HousekeepingLock', function (): void {

    it('tryAcquire returns true on a fresh DB and seeds the singleton row', function (): void {
        $lock = new HousekeepingLock();

        expect($lock->tryAcquire(30))->toBeTrue();

        $row = Capsule::table('worker_housekeeping_locks')->where('id', 1)->first();
        expect($row)->not->toBeNull()
            ->and(strtotime($row->claimed_until . ' UTC'))->toBeGreaterThan(time());
    });

    it('tryAcquire returns false when another caller already holds the lock', function (): void {
        // Pre-seed an in-flight lock; a concurrent caller must NOT take it.
        Capsule::table('worker_housekeeping_locks')->insert([
            'id'            => 1,
            'claimed_until' => date(HOUSEKEEPING_LOCK_DT, time() + 60),
            'claimed_by'    => 999,
        ]);

        $lock = new HousekeepingLock();
        expect($lock->tryAcquire(30))->toBeFalse();
    });

    it('tryAcquire steals the lock once claimed_until is in the past', function (): void {
        // The CAS-update path: an expired lease must be re-claimable so
        // a crashed caller's lock doesn't suppress every other browser.
        Capsule::table('worker_housekeeping_locks')->insert([
            'id'            => 1,
            'claimed_until' => date(HOUSEKEEPING_LOCK_DT, time() - 60),
            'claimed_by'    => 999,
        ]);

        $lock = new HousekeepingLock();
        expect($lock->tryAcquire(30))->toBeTrue();
    });

    it('tryAcquire returns false (fail-closed) when the lock table is missing', function (): void {
        // Drop the table mid-test so every query throws — tryAcquire must
        // swallow the Throwable and report false. The transaction rollback
        // in afterEach restores the schema for the next test.
        Capsule::schema()->drop('worker_housekeeping_locks');

        $lock = new HousekeepingLock();
        expect($lock->tryAcquire(30))->toBeFalse();
    });

    it('release is a no-op when the lock table is missing (suppresses Throwable)', function (): void {
        // The catch block in release() must swallow any DB error so the
        // controller's `finally` doesn't mask the real response.
        Capsule::schema()->drop('worker_housekeeping_locks');

        $lock = new HousekeepingLock();
        expect(fn() => $lock->release())->not->toThrow(Throwable::class);
    });

    it('release writes the past-claimed_until sentinel and clears the row idempotently', function (): void {
        // Acquire then release; release is idempotent — calling it twice
        // (e.g. on a finally + crash-recovery path) must not throw.
        $lock = new HousekeepingLock();
        $lock->tryAcquire(30);

        $lock->release();
        $lock->release();

        $row = Capsule::table('worker_housekeeping_locks')->where('id', 1)->first();
        expect($row)->not->toBeNull()
            ->and(strtotime($row->claimed_until . ' UTC'))->toBeLessThanOrEqual(time());
    });
});
