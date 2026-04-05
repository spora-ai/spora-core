<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Simple in-memory IP-based rate limiter for auth endpoints.
 * Uses a sliding window approach: max $maxAttempts per $windowSeconds.
 */
final class RateLimiter
{
    /** @var array<string, array{count: int, window_start: int}> */
    private static array $buckets = [];

    /**
     * Record an attempt and check if the client is rate-limited.
     *
     * @param string $identifier Client IP address
     * @param int $maxAttempts Maximum attempts allowed per window
     * @param int $windowSeconds Window size in seconds
     * @return bool true if rate-limited (request should be rejected), false otherwise
     */
    public static function attempt(string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $now = time();

        if (! isset(self::$buckets[$identifier])) {
            self::$buckets[$identifier] = ['count' => 0, 'window_start' => $now];
        }

        $bucket = &self::$buckets[$identifier];

        // Reset window if expired
        if (($now - $bucket['window_start']) >= $windowSeconds) {
            $bucket = ['count' => 0, 'window_start' => $now];
        }

        if ($bucket['count'] >= $maxAttempts) {
            return true; // Rate limited
        }

        $bucket['count']++;

        return false;
    }

    /**
     * Get remaining attempts for a client.
     *
     * @param string $identifier Client IP address
     * @param int $maxAttempts Maximum attempts allowed per window
     * @param int $windowSeconds Window size in seconds
     * @return int Remaining attempts in current window
     */
    public static function remaining(string $identifier, int $maxAttempts, int $windowSeconds): int
    {
        $now = time();

        if (! isset(self::$buckets[$identifier])) {
            return $maxAttempts;
        }

        $bucket = self::$buckets[$identifier];

        if (($now - $bucket['window_start']) >= $windowSeconds) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - $bucket['count']);
    }

    /**
     * Get seconds until the rate limit window resets.
     *
     * @param string $identifier Client IP address
     * @param int $windowSeconds Window size in seconds
     * @return int Seconds until reset (0 if no bucket exists)
     */
    public static function retryAfter(string $identifier, int $windowSeconds): int
    {
        $now = time();

        if (! isset(self::$buckets[$identifier])) {
            return 0;
        }

        $bucket = self::$buckets[$identifier];
        $elapsed = $now - $bucket['window_start'];

        return max(0, $windowSeconds - $elapsed);
    }

    /**
     * Clear the rate limiter bucket for a client (e.g., after successful login).
     *
     * @param string $identifier Client IP address
     */
    public static function clear(string $identifier): void
    {
        unset(self::$buckets[$identifier]);
    }

    /**
     * Reset all buckets (useful for testing).
     */
    public static function resetAll(): void
    {
        self::$buckets = [];
    }
}
