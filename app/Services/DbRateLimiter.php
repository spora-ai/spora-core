<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTimeImmutable;
use DateTimeZone;
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

    /**
     * Record a hit for $key and return true if the bucket is under $maxAttempts
     * within the rolling $windowSeconds window. Hits older than the window are
     * pruned before counting.
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowString = $now->format(self::DB_DATETIME_FORMAT);
        $cutoffString = $now->modify(sprintf('-%d seconds', $windowSeconds))->format(self::DB_DATETIME_FORMAT);

        try {
            // Prune hits older than the window to keep the table small.
            RatelimitHit::query()
                ->where('key', $key)
                ->where('hit_at', '<=', $cutoffString)
                ->delete();

            $count = RatelimitHit::query()->where('key', $key)->count();
            if ($count >= $maxAttempts) {
                return false;
            }

            (new RatelimitHit([
                'key'    => $key,
                'hit_at' => $nowString,
            ]))->save();
            return true;
        } catch (Throwable) {
            // Fail open — a DB hiccup shouldn't lock the operator out of /tick.
            return true;
        }
    }
}
