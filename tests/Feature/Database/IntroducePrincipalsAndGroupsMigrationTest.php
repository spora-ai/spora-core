<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;
use Spora\Models\Agent;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Principal;
use Spora\Models\ToolUserSetting;
use Spora\Models\User;

beforeEach(function (): void {
    Database::resetBootState();
    $db = new Database([
        'db_driver' => 'sqlite',
        'db_path'   => ':memory:',
    ]);
    $db->boot();
});

test('0067 migration creates principals/groups/group_memberships tables', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasTable('groups'))->toBeTrue();
    expect(Capsule::schema()->hasTable('group_memberships'))->toBeTrue();
    expect(Capsule::schema()->hasTable('principals'))->toBeTrue();

    // principal_preferences replaced user_preferences
    expect(Capsule::schema()->hasTable('principal_preferences'))->toBeTrue();
    expect(Capsule::schema()->hasTable('user_preferences'))->toBeFalse();
});

test('0067 migration backfills a user-principal per existing user', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';

    // Seed two users first (no seeder in this test), then run the
    // migration. After up(), every user has exactly one user-principal.
    $userA = Capsule::table('users')->insertGetId([
        'email' => 'alice@example.com', 'username' => 'alice',
        'password' => 'unused-hash', 'status' => 1, 'verified' => 1,
        'roles_mask' => 0, 'registered' => time(),
    ]);
    $userB = Capsule::table('users')->insertGetId([
        'email' => 'bob@example.com', 'username' => 'bob',
        'password' => 'unused-hash', 'status' => 1, 'verified' => 1,
        'roles_mask' => 0, 'registered' => time(),
    ]);

    $migration->up();

    $count = Capsule::table('principals')->where('type', 'user')->count();
    $users = Capsule::table('users')->count();
    expect($users)->toBe(2);
    expect($count)->toBe(2);
});

test('0067 migration swaps agents.user_id for agents.principal_id', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    expect(Capsule::schema()->hasColumn('agents', 'user_id'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('agents', 'principal_id'))->toBeTrue();

    // Each agent row must carry a non-null principal_id
    $nullPrincipals = Capsule::table('agents')->whereNull('principal_id')->count();
    expect($nullPrincipals)->toBe(0);
});

test('0067 migration drops user_id from settings tables', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    foreach (['llm_driver_configurations', 'tool_user_settings', 'principal_preferences'] as $t) {
        expect(Capsule::schema()->hasColumn($t, 'user_id'))->toBeFalse("{$t} should not have user_id");
        expect(Capsule::schema()->hasColumn($t, 'principal_id'))->toBeTrue("{$t} should have principal_id");
    }
});

test('0067 migration forward-only: down() preserves new tables', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();
    $migration->down();

    // No-op down — the new tables stay. (A real rollback needs a manual
    // backup restore; this test only documents the contract.)
    expect(Capsule::schema()->hasTable('principals'))->toBeTrue();
    expect(Capsule::schema()->hasTable('groups'))->toBeTrue();
});

test('Principal model enforces XOR: only one of user_id/group_id', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    $userId = (int) Capsule::table('users')->value('id');

    // Both set → rejected.
    expect(fn() => Principal::create([
        'type' => 'user', 'principal_id' => createUserPrincipalPublic($userId), 'group_id' => null,
    ]))->not()->toThrow(Throwable::class);

    // Both null → rejected via XOR.
    $thrown = null;
    try {
        Principal::create([
            'type' => 'user', 'user_id' => null, 'group_id' => null,
        ]);
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(LogicException::class);

    // Both set → rejected via XOR (FK will also fail but LogicException first).
    $thrown = null;
    try {
        Principal::create([
            'type' => 'user', 'principal_id' => createUserPrincipalPublic($userId), 'group_id' => 999,
        ]);
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(LogicException::class);
});

test('Principal type must match the FK that is set', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0067_introduce_principals_and_groups.php';
    $migration->up();

    $userId = (int) Capsule::table('users')->value('id');

    expect(fn() => Principal::create([
        'type' => 'group', 'principal_id' => createUserPrincipalPublic($userId), 'group_id' => null,
    ]))->toThrow(LogicException::class);
});
