<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;

defined('SENTINEL_TEST_PASSWORD') || define('SENTINEL_TEST_PASSWORD', 'Password1!');

/**
 * `resolveForToolExecute()` returns two structurally different sentinels when
 * it cannot resolve a principal, and only one of them is `0`. A tenant-scoped
 * feature that guards on `$principalId === 0` therefore passes the dangling
 * case straight through. These tests pin both so a future "cleanup" of the
 * `0` branch shows up as a failing assertion instead of a silent widening.
 */
it('returns principalId 0 when the agent row does not exist', function (): void {
    bootAuthLayer();
    $context = (new PrincipalResolver())->resolveForToolExecute(999_999_999);

    expect($context->principalId)->toBe(0)
        ->and($context->ownerUserId)->toBeNull()
        ->and($context->runnerUserId)->toBeNull()
        ->and($context->isResolvable())->toBeFalse();
});

it('returns the dangling non-zero principal id when the principal row is gone', function (): void {
    bootAuthLayer();

    // `agents.principal_id` carries a RESTRICT foreign key to `principals`, so
    // the dangling state cannot be inserted directly — it has to be produced by
    // deleting the parent, which is exactly how it happens in production (a
    // principal row removed by a migration, a restore, or a hand-edited DB).
    $driver = Capsule::connection()->getDriverName();
    if ($driver === 'sqlite') {
        Capsule::statement('PRAGMA defer_foreign_keys = ON');
    } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
        Capsule::statement('SET FOREIGN_KEY_CHECKS=0');
    }
    try {
        $principalId = (int) Capsule::table('principals')->insertGetId([
            'type'   => Principal::TYPE_USER,
            'user_id' => 1,
        ]);
        $agentId = (int) Capsule::table('agents')->insertGetId([
            'principal_id' => $principalId,
            'name'         => 'Orphaned Agent',
            'max_steps'    => 10,
            'is_active'    => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        Capsule::table('principals')->where('id', $principalId)->delete();
    } finally {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Capsule::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    $context = (new PrincipalResolver())->resolveForToolExecute($agentId);

    // This is the case a `<= 0` guard was written to catch and does not.
    expect($context->principalId)->toBe($principalId)
        ->and($context->principalId)->not->toBe(0)
        ->and($context->isResolvable())->toBeTrue();
});

it('resolves a live principal normally', function (): void {
    bootAuthLayer();

    $principalId = createUserPrincipalPublic((int) bootAuth(bootAuthLayer(), 'sentinel@example.com', SENTINEL_TEST_PASSWORD, 'Sentinel'));
    $agentId = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Live Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ])->id;

    $context = (new PrincipalResolver())->resolveForToolExecute($agentId);

    expect($context->principalId)->toBe($principalId)
        ->and($context->isResolvable())->toBeTrue()
        ->and($context->ownerUserId)->not->toBeNull();
});

it('isResolvable is a structural check only, not a liveness probe', function (): void {
    // Documents the deliberate limit: a hand-built context with a plausible
    // id reports resolvable, because proving otherwise needs the database.
    $context = new PrincipalContext(424_242, Principal::TYPE_USER, null, null);

    expect($context->isResolvable())->toBeTrue();
});

it('isResolvable rejects zero and negative principal ids', function (): void {
    expect((new PrincipalContext(0, Principal::TYPE_USER, null, null))->isResolvable())->toBeFalse()
        ->and((new PrincipalContext(-1, Principal::TYPE_USER, null, null))->isResolvable())->toBeFalse();
});
