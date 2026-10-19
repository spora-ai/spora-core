<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\RatelimitHit;
use Spora\Services\DbRateLimiter;

/**
 * The limiter has to count a burst, not a trickle.
 *
 * It previously deduplicated hits that landed in the same second — the table's
 * primary key was `(key, hit_at)` — and the resulting constraint violation was
 * swallowed as "fail open". A caller sending more requests than the limit
 * inside one second was recorded as having sent one.
 *
 * `freezeTime()` pins every hit to the same wall-clock second, which is the
 * case that used to collide; without it this test would pass by luck whenever
 * the loop happened to straddle a second boundary.
 */
it('refuses a burst that arrives inside a single second', function (): void {
    $limiter = new DbRateLimiter();

    Carbon\Carbon::setTestNow(Carbon\Carbon::now()->startOfSecond());

    $accepted = 0;

    for ($i = 0; $i < 12; $i++) {
        if ($limiter->attempt('burst-key', 5, 60)) {
            $accepted++;
        }
    }

    Carbon\Carbon::setTestNow();

    expect($accepted)->toBe(5);

    // Five rows, not one: every accepted hit is its own row now.
    expect(RatelimitHit::query()->where('key', 'burst-key')->count())->toBe(5);
});

it('still fails open when the table is unusable', function (): void {
    Capsule::schema()->drop('ratelimit_hits');

    expect((new DbRateLimiter())->attempt('gone', 1, 60))->toBeTrue();
});
