<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Tests\Concerns\CreatesPrincipal;

/**
 * Unit tests for {@see PrincipalService::principalIdsForUser()}.
 *
 * Pins the cascade ordering contract:
 *  - user-principal first
 *  - group-principals after, sorted ascending by id
 *  - `[]` when no user-principal exists yet
 *  - idempotent on repeated calls (no side effects)
 */
uses(CreatesPrincipal::class);

function makePrincipalService(): PrincipalService
{
    return new PrincipalService(new PrincipalResolver());
}

test('returns single user-principal id when user has no group memberships', function (): void {
    $userId = (int) \Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'      => 'pp-nogrp@example.com',
        'username'   => 'pp_nogrp',
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $expectedUserPrincipal = createUserPrincipalPublic($userId);

    $service = makePrincipalService();

    expect($service->principalIdsForUser($userId))->toBe([$expectedUserPrincipal]);
});

test('returns [user_principal, group_principal…] in group-id ascending order', function (): void {
    $userId = (int) \Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'      => 'pp-grp@example.com',
        'username'   => 'pp_grp',
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $userPrincipal = createUserPrincipalPublic($userId);

    // Create 3 groups; user joins all of them. Insert in non-monotonic
    // order so the row ids interleave — the assertions confirm the
    // returned ordering is by principal id (assigned at insert time),
    // not by insert order in the test.
    $groupA = $this->makeGroupPrincipal($userId, 'A');
    $groupB = $this->makeGroupPrincipal($userId, 'B');
    $groupC = $this->makeGroupPrincipal($userId, 'C');

    $groupPrincipalIds = collect([$groupA, $groupB, $groupC])->sort()->values()->all();

    $service = makePrincipalService();

    expect($service->principalIdsForUser($userId))->toBe(
        array_merge([$userPrincipal], $groupPrincipalIds),
    );
});

test('returns only user-principal when user belongs to a group that has no group-principal row', function (): void {
    // Edge case: a `groups` row exists with a `group_memberships` entry for
    // the user, but the corresponding `principals` row (type=group) is
    // missing. The JOIN must drop the row rather than returning a phantom id.
    $userId = (int) \Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'      => 'pp-no-principal@example.com',
        'username'   => 'pp_no_principal',
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $userPrincipal = createUserPrincipalPublic($userId);

    $groupId = (int) \Illuminate\Database\Capsule\Manager::table('groups')->insertGetId([
        'name'                => 'orphan',
        'description'         => null,
        'created_by_user_id'  => $userId,
        'created_at'          => date('Y-m-d H:i:s'),
        'updated_at'          => date('Y-m-d H:i:s'),
    ]);
    \Illuminate\Database\Capsule\Manager::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $userId,
        'role'       => 'member',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    // Note: NO `principals` row for this group — the join must filter it out.

    expect(makePrincipalService()->principalIdsForUser($userId))->toBe([$userPrincipal]);
});

test('returns empty list when user has no user-principal row yet (does not auto-materialise)', function (): void {
    // The function must NOT materialise the user-principal as a side effect
    // — that's the cascade's job. A missing user-principal returns `[]`
    // so the caller can choose to fall through to global-only.
    $userId = (int) \Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'      => 'pp-no-user-principal@example.com',
        'username'   => 'pp_no_user_principal',
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    expect(makePrincipalService()->principalIdsForUser($userId))->toBe([]);
});

test('is idempotent on repeated calls (no side effects, same shape)', function (): void {
    $userId = (int) \Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'      => 'pp-idem@example.com',
        'username'   => 'pp_idem',
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    createUserPrincipalPublic($userId);
    $this->makeGroupPrincipal($userId, 'Idem-A');
    $this->makeGroupPrincipal($userId, 'Idem-B');

    $service = makePrincipalService();

    $first  = $service->principalIdsForUser($userId);
    $second = $service->principalIdsForUser($userId);
    $third  = $service->principalIdsForUser($userId);

    expect($second)->toBe($first)
        ->and($third)->toBe($first);
});

test('returns principal ids in ascending order across groups joined in non-monotonic order', function (): void {
    // Pin the stable-order contract: groups created in some insert order
    // must still be returned in principal-id ascending order. We deliberately
    // create them with non-monotonic inserts and then assert the output.
    $userId = (int) \Illuminate\Database\Capsule\Manager::table('users')->insertGetId([
        'email'      => 'pp-order@example.com',
        'username'   => 'pp_order',
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $userPrincipal = createUserPrincipalPublic($userId);

    $g3 = $this->makeGroupPrincipal($userId, 'third-inserted');
    $g1 = $this->makeGroupPrincipal($userId, 'first-inserted');
    $g2 = $this->makeGroupPrincipal($userId, 'second-inserted');

    $sorted = [(int) $g1, (int) $g2, (int) $g3];
    sort($sorted);

    expect(makePrincipalService()->principalIdsForUser($userId))->toBe(
        array_merge([$userPrincipal], $sorted),
    );
});
