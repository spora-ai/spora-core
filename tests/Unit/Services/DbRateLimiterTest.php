<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\RatelimitHit;
use Spora\Services\DbRateLimiter;

defined('DB_RL_PASSWORD') || define('DB_RL_PASSWORD', 'Password1!');
const DB_RL_DT = 'Y-m-d H:i:s';

describe('DbRateLimiter', function (): void {

    it('attempt returns true while under the cap', function (): void {
        $limiter = new DbRateLimiter();

        expect($limiter->attempt('client_a', 3, 60))->toBeTrue();
    });

    it('attempt returns false once the rolling-window cap is reached', function (): void {
        // Pre-seed three hits within the window — the next attempt() must
        // observe count >= 3 and reject. Hits older than the window are
        // pruned first, so timestamps are in the recent past.
        Capsule::table('ratelimit_hits')->insert([
            ['key' => 'client_b', 'hit_at' => date(DB_RL_DT, time() - 5)],
            ['key' => 'client_b', 'hit_at' => date(DB_RL_DT, time() - 10)],
            ['key' => 'client_b', 'hit_at' => date(DB_RL_DT, time() - 15)],
        ]);

        $limiter = new DbRateLimiter();
        expect($limiter->attempt('client_b', 3, 60))->toBeFalse();
    });

    it('attempt prunes hits older than the window before counting', function (): void {
        // A stale hit (older than the window) must NOT count toward the
        // cap — otherwise a bucket would stay full forever.
        Capsule::table('ratelimit_hits')->insert([
            ['key' => 'client_c', 'hit_at' => date(DB_RL_DT, time() - 120)], // outside 60s window
        ]);

        $limiter = new DbRateLimiter();
        expect($limiter->attempt('client_c', 2, 60))->toBeTrue();
    });

    it('attempt returns true (fail-open) when the ratelimit_hits table is missing', function (): void {
        // A DB hiccup must NOT lock the operator out of /tick or
        // /housekeeping — fail-open so a transient outage doesn't escalate
        // into a complete feature outage. Drop the table mid-test to force
        // every query inside attempt() to throw.
        //
        // On SQLite `:memory:` the table is fresh per test, so dropping it
        // here was harmless. On MariaDB/MySQL CI the DB is per-worker and
        // persists across tests — DDL auto-commits on InnoDB so a rolled-back
        // transaction cannot undo the DROP. Restore the database afterwards
        // so the next test in this file (`attempt isolates bucket counts per
        // key`) can still insert into it.
        TestDatabaseFactory::freshDatabase();
        Capsule::schema()->drop('ratelimit_hits');

        try {
            $limiter = new DbRateLimiter();
            expect($limiter->attempt('client_d', 3, 60))->toBeTrue();
        } finally {
            // The whole database, not the table alone: re-creating the table
            // by hand restored the pre-0095 schema — a composite
            // `(key, hit_at)` primary key and no surrogate id — which the
            // burst test tripped over on MySQL/MariaDB.
            TestDatabaseFactory::freshDatabase();
        }
    });

    it('attempt isolates bucket counts per key', function (): void {
        // Filling one key's window must NOT affect another key — the
        // rolling-window count is per-key.
        Capsule::table('ratelimit_hits')->insert([
            ['key' => 'k1', 'hit_at' => date(DB_RL_DT, time() - 1)],
            ['key' => 'k1', 'hit_at' => date(DB_RL_DT, time() - 2)],
            ['key' => 'k1', 'hit_at' => date(DB_RL_DT, time() - 3)],
        ]);

        $limiter = new DbRateLimiter();
        expect($limiter->attempt('k2', 3, 60))->toBeTrue()
            ->and($limiter->attempt('k1', 3, 60))->toBeFalse();
    });

    it('never records more hits than the cap allows when a competing request lands mid-window', function (): void {
        // Reading the count and then writing the hit leaves a window in
        // which a competing request is invisible: both read the same count
        // and both pass the cap. This simulates that request.
        $key = 'race_' . bin2hex(random_bytes(4));

        /** @var array{armed: bool, injected: bool} $state */
        $state = ['armed' => true, 'injected' => false];

        Capsule::connection()->beforeExecuting(function (string $query) use (&$state, $key): void {
            if (! $state['armed'] || $state['injected']) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'insert into') || ! str_contains($sql, 'ratelimit_hits')) {
                return;
            }

            // Armed first: the injection is an insert of its own.
            $state['injected'] = true;
            Capsule::table('ratelimit_hits')->insert([
                'key'    => $key,
                'hit_at' => gmdate(DB_RL_DT),
            ]);
        });

        try {
            $accepted = (new DbRateLimiter())->attempt($key, 1, 60);
        } finally {
            // The hook lives on the connection, which outlives the test.
            $state['armed'] = false;
        }

        // The competing request took the only slot, so this one is refused.
        expect($accepted)->toBeFalse();
        // And the bucket never holds more hits than the cap allows.
        expect(RatelimitHit::query()->where('key', $key)->count())->toBe(1);
    });
});
