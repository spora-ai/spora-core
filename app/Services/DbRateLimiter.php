<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConcurrencyErrorDetector;
use Spora\Models\RatelimitHit;
use Throwable;

/**
 * DB-backed rate limiter for endpoints that need to share state across
 * PHP-FPM workers (/tick, /housekeeping). The in-memory RateLimiter is
 * fine for single-process auth flows; this implementation persists hits
 * so all workers see the same window.
 *
 * Operators can swap to a Redis backend later — the public surface is
 * deliberately minimal.
 */
final class DbRateLimiter
{
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** How often a deadlocked attempt is retried before the hit is failed open. */
    private const MAX_TRIES = 3;

    /**
     * Record a hit for $key and return true if the bucket is under $maxAttempts
     * within the rolling $windowSeconds window. Hits older than the window are
     * pruned before counting.
     *
     * The prune and the insert share one transaction: as separate statements
     * they take the table's two row ranges in different transactions, and
     * concurrent attempts for the same key cross over each other there.
     * InnoDB resolves that as a deadlock, and a deadlocked attempt is failed
     * open — which would let one request past the cap just enforced.
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowString = $now->format(self::DB_DATETIME_FORMAT);
        $cutoffString = $now->modify(sprintf('-%d seconds', $windowSeconds))->format(self::DB_DATETIME_FORMAT);

        for ($try = 1; $try <= self::MAX_TRIES; $try++) {
            try {
                return $this->recordHit($key, $maxAttempts, $nowString, $cutoffString);
            } catch (Throwable $e) {
                // A deadlock is transient: the transaction rolled back, so
                // nothing was recorded and the colliding transaction has
                // committed. Retrying re-reads the count with that hit in it.
                if ($try < self::MAX_TRIES && $this->causedByConcurrencyError($e)) {
                    continue;
                }

                // Fail open — a DB hiccup shouldn't lock the operator out of /tick.
                return true;
            }
        }

        return true;
    }

    /** Prunes the stale hits and records this one, if the window has room. */
    private function recordHit(string $key, int $maxAttempts, string $nowString, string $cutoffString): bool
    {
        return Capsule::connection()->transaction(function () use ($key, $maxAttempts, $nowString, $cutoffString): bool {
            // Prune hits older than the window to keep the table small.
            RatelimitHit::query()
                ->where('key', $key)
                ->where('hit_at', '<=', $cutoffString)
                ->delete();

            // Nothing written means the window had no room left — the
            // over-the-limit answer.
            return $this->insertHitWithinLimit($key, $maxAttempts, $nowString, $cutoffString) === 1;
        });
    }

    /**
     * Records the hit, but only while the window still has room for it.
     *
     * Counting the window and writing the hit are ONE statement: the `where`
     * clause counts the hits still inside the window, the `insert` writes
     * this one, and a statement is atomic. Reading the count first and
     * writing afterwards leaves a window in which a competing request slips
     * in unnoticed — both read the same count and both pass the cap.
     *
     * The cap is bound as an integer: SQLite compares an integer against a
     * TEXT value by type rather than by value, so a text-bound number never
     * compares as less than an integer count and the cap would stop applying.
     */
    private function insertHitWithinLimit(string $key, int $maxAttempts, string $nowString, string $cutoffString): int
    {
        $grammar = Capsule::connection()->getQueryGrammar();

        $counted = Capsule::query()
            ->selectRaw('? as ' . $grammar->wrap('key'), [$key])
            ->selectRaw('? as ' . $grammar->wrap('hit_at'), [$nowString])
            ->whereRaw(
                '(select count(*) from ' . $grammar->wrapTable('ratelimit_hits')
                . ' where ' . $grammar->wrap('ratelimit_hits.key') . ' = ?'
                . ' and ' . $grammar->wrap('ratelimit_hits.hit_at') . ' > ?) < ?',
                [$key, $cutoffString, $maxAttempts],
            );

        return Capsule::table('ratelimit_hits')->insertUsing(['key', 'hit_at'], $counted);
    }

    /**
     * Whether the failure is a deadlock or a lock-wait timeout — the two
     * transient conditions a concurrent attempt can provoke.
     */
    private function causedByConcurrencyError(Throwable $e): bool
    {
        return (new ConcurrencyErrorDetector())->causedByConcurrencyError($e);
    }

    /**
     * Seconds until the bucket is below `$maxAttempts` again, for a
     * `Retry-After` header.
     *
     * The oldest hit in the window is the one that frees the first slot, so
     * the wait is the remainder of the window from that hit. Returns the
     * whole window when the window is empty or the query fails, which is the
     * safe direction: it over-waits rather than under-waits.
     */
    public function retryAfterSeconds(string $key, int $windowSeconds): int
    {
        try {
            $oldest = RatelimitHit::query()
                ->where('key', $key)
                ->min('hit_at');
        } catch (Throwable) {
            return $windowSeconds;
        }

        if ($oldest === null) {
            return $windowSeconds;
        }

        $wait = $windowSeconds - (time() - strtotime((string) $oldest . ' UTC'));

        return max(1, min($wait, $windowSeconds));
    }
}
