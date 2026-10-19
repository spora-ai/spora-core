<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Spora\Auth\AgentApiKeyIssuer;
use Spora\Core\Config;
use Spora\Http\Middleware\AgentApiKeyMiddleware;
use Spora\Models\AgentApiKey;
use Spora\Services\DbRateLimiter;
use Spora\Services\PrincipalResolver;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * `last_used_at` is the only liveness signal an operator has before revoking a
 * key, so it has to advance.
 *
 * The column is written in UTC and was read back through Eloquent's `datetime`
 * cast, which parses in the process default timezone. `Kernel` never calls
 * `date_default_timezone_set()` — only the worker command does — so on a host
 * whose php.ini names a local zone the two disagreed by the UTC offset. West
 * of UTC that froze the column at mint time forever; east of it, every single
 * request performed the write the minute-resolution guard exists to avoid.
 */
it('advances last_used_at on a host whose timezone is not UTC', function (): void {
    $seed = AgentApiTestSupport::seedAgent('tz');
    [$key] = AgentApiTestSupport::mintKey($seed['agentId'], 'tz');

    // Two hours ago: outside the 60s window, so a correct stamp advances it,
    // but inside the four-hour skew, so the mis-parsed read lands in the future
    // and the guard refuses. A day-old value would be written either way and
    // prove nothing.
    $written = Carbon::now('UTC')->subHours(2);
    $key->forceFill(['last_used_at' => $written])->save();

    $original = date_default_timezone_get();

    try {
        // An hour behind UTC: the cast reads the stored UTC string as local, so
        // the delta comes out four hours negative, the guard is satisfied, and
        // the column stops advancing.
        date_default_timezone_set('America/New_York');

        // Re-read: the bug needs the cast to run *after* the zone changes. A
        // model still carrying the Carbon it was written with would answer
        // correctly whatever the process timezone were, which is exactly why
        // this passed when it was first written against the unfixed code.
        $fresh = AgentApiKey::query()->findOrFail($key->getKey());

        $middleware = new AgentApiKeyMiddleware(
            new AgentApiKeyIssuer(),
            new PrincipalResolver(),
            new DbRateLimiter(),
            new Config([]),
        );

        (new ReflectionMethod($middleware, 'touchLastUsed'))->invoke($middleware, $fresh);

        expect($fresh->getRawOriginal('last_used_at'))->not->toBe($written->format('Y-m-d H:i:s'));
    } finally {
        date_default_timezone_set($original);
    }
});

it('leaves last_used_at alone inside the resolution window', function (): void {
    $seed = AgentApiTestSupport::seedAgent('tz-window');
    [$key] = AgentApiTestSupport::mintKey($seed['agentId'], 'tz-window');

    $stamped = Carbon::now('UTC')->subSeconds(5);
    $key->forceFill(['last_used_at' => $stamped])->save();

    $middleware = new AgentApiKeyMiddleware(
        new AgentApiKeyIssuer(),
        new PrincipalResolver(),
        new DbRateLimiter(),
        new Config([]),
    );

    (new ReflectionMethod($middleware, 'touchLastUsed'))->invoke($middleware, $key);

    // Five seconds ago is inside the window, so the hot path must not write.
    expect($key->getRawOriginal('last_used_at'))->toBe($stamped->format('Y-m-d H:i:s'));
});

it('reads a key that has never been used without error', function (): void {
    $seed = AgentApiTestSupport::seedAgent('tz-null');
    [$key] = AgentApiTestSupport::mintKey($seed['agentId'], 'tz-null');

    $middleware = new AgentApiKeyMiddleware(
        new AgentApiKeyIssuer(),
        new PrincipalResolver(),
        new DbRateLimiter(),
        new Config([]),
    );

    (new ReflectionMethod($middleware, 'touchLastUsed'))->invoke($middleware, $key);

    expect($key->getRawOriginal('last_used_at'))->not->toBeNull();
});
