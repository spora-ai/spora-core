<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Throwable;

/**
 * Shared lock for /worker/housekeeping calls.
 *
 * Single-row DB-backed lock (worker_housekeeping_locks.id is always 1).
 * Without this, every open browser would race to dispatch the same
 * scheduled run on each /housekeeping poll. The 30s no-op window bounds
 * call overlap; the reaper and scheduled-run processor inside the
 * handler are idempotent within that window, so an acquire that races
 * and fails is safe to ignore (caller returns 204).
 */
final class HousekeepingLock
{
    private const SINGLETON_ID = 1;
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Try to acquire the lock for $seconds.
     * Returns true on success, false if another caller already holds it.
     */
    public function tryAcquire(int $seconds): bool
    {
        $now = gmdate(self::DB_DATETIME_FORMAT);
        $until = gmdate(self::DB_DATETIME_FORMAT, time() + $seconds);

        try {
            $row = Capsule::table('worker_housekeeping_locks')->where('id', self::SINGLETON_ID)->first();
            if ($row === null) {
                return $this->insertInitialLock($until);
            }

            return $this->casUpdateLock($row, $now, $until);
        } catch (Throwable) {
            return false;
        }
    }

    private function insertInitialLock(string $until): bool
    {
        // caller_id not currently threaded through; placeholder for analytics.
        Capsule::table('worker_housekeeping_locks')->insert([
            'id'            => self::SINGLETON_ID,
            'claimed_until' => $until,
            'claimed_by'    => 0,
        ]);
        return true;
    }

    private function casUpdateLock(object $row, string $now, string $until): bool
    {
        $existing = (string) $row->claimed_until;
        if ($existing > $now) {
            return false;
        }
        $affected = Capsule::table('worker_housekeeping_locks')
            ->where('id', self::SINGLETON_ID)
            ->where('claimed_until', '<=', $now)
            ->update([
                'claimed_until' => $until,
                'claimed_by'    => 0,
            ]);
        return $affected > 0;
    }

    /**
     * Release the lock. Idempotent — clearing a row that's already empty
     * is a no-op.
     */
    public function release(): void
    {
        try {
            Capsule::table('worker_housekeeping_locks')
                ->where('id', self::SINGLETON_ID)
                ->update([
                    'claimed_until' => gmdate(self::DB_DATETIME_FORMAT, time() - 1),
                    'claimed_by'    => 0,
                ]);
        } catch (Throwable) {
            // Best-effort — the row may have been removed by an operator.
        }
    }
}
