<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\AgentToolOverride;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Models\ToolConfiguration;
use Spora\Models\ToolUserSetting;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Tests\Concerns\CreatesPrincipal;
use Tests\Fixtures\TestTool;

/**
 * Tests for the **4-level** tool settings cascade:
 *  1. Tool schema defaults
 *  2. Global tool configuration (`tool_configurations`)
 *  3. Group-principal settings (`tool_user_settings` keyed by type=group)
 *  4. User-principal settings (`tool_user_settings` keyed by type=user)
 *  5. Agent-specific overrides (`agent_tool_overrides`)
 *
 * The cascade walks `global → group[0..N] → user → agent` so the
 * user-principal wins on conflict with group-scoped settings, and the
 * agent override wins over both. Groups are iterated in `principal.id`
 * ascending order so the iteration is stable across calls.
 *
 * Pre-existing {@see ToolConfigServiceSettingsCascadeTest} covers the
 * legacy `global → user → agent` shape; this file covers the new
 * group-aware behavior and pins the cascade ordering contract.
 */
uses(CreatesPrincipal::class);

function makeCascadeToolConfigService(): ToolConfigService
{
    return new ToolConfigService(
        new SecurityManager(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Logger('test'),
        [TestTool::class],
    );
}

/**
 * Insert an agents row owned by the caller's user-principal. Mirrors
 * {@see CreatesPrincipal::makeAgentWithPrincipal()} without requiring
 * the trait to be in scope at this test-file's closure level.
 */
function makeTestAgentForUser(int $userId, string $name = 'A'): int
{
    $principalId = createUserPrincipalPublic($userId);
    return (int) Capsule::table('agents')->insertGetId([
        'name'         => $name,
        'principal_id' => $principalId,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Materialise: user, user-principal, group, group-principal, and the
 * user's membership row in the group. Returns the resolved principal
 * ids in the order the cascade will consult them.
 *
 * @return array{0: int, 1: int, 2: int} [userId, userPrincipalId, groupPrincipalId]
 */
function setupGroupCascadeFixture(int $suffix = 0): array
{
    $userId = (int) Capsule::table('users')->insertGetId([
        'email'      => "cascade-{$suffix}@example.com",
        'username'   => "cascade_{$suffix}",
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $userPrincipalId = Capsule::table('principals')
        ->insertGetId([
            'type'       => Principal::TYPE_USER,
            'user_id'    => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    $userPrincipalId = (int) $userPrincipalId;

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => "cascade-group-{$suffix}",
        'description'        => null,
        'created_by_user_id' => $userId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);
    $groupPrincipalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_GROUP,
        'group_id'   => $groupId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $userId,
        'role'       => GroupMembership::ROLE_MEMBER,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return [$userId, $userPrincipalId, $groupPrincipalId];
}

test('regression: cascade falls back to global when user has no group memberships', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('regress-no-group@example.com', 'Password1!', 'RegressNoGroup');

    $svc = makeCascadeToolConfigService();
    $agentId = makeTestAgentForUser($userId);

    $svc->putGlobalSettings(TestTool::class, [
        'api_key' => 'global-key',
        'max_results' => '50',
    ]);

    $effective = $svc->getEffectiveSettings(TestTool::class, $agentId, $userId);
    expect($effective['api_key'])->toBe('global-key')
        ->and($effective['max_results'])->toBe('50');
});

test('cascade: group config fills the slot when user has none of their own', function (): void {
    $svc = makeCascadeToolConfigService();
    [$userId, , $groupPrincipalId] = setupGroupCascadeFixture(1);
    $agentId = makeTestAgentForUser($userId);

    $svc->putGlobalSettings(TestTool::class, ['max_results' => '50']);
    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalId, [
        'api_key' => 'group-shared-key',
        'max_results' => '77',
    ]);

    $effective = $svc->getEffectiveSettings(TestTool::class, $agentId, $userId);
    expect($effective['api_key'])->toBe('group-shared-key')
        ->and($effective['max_results'])->toBe('77'); // group overrides global
});

test('cascade: user override wins when both user and group have a config', function (): void {
    $svc = makeCascadeToolConfigService();
    [$userId, $userPrincipalId, $groupPrincipalId] = setupGroupCascadeFixture(2);
    $agentId = makeTestAgentForUser($userId);

    $svc->putGlobalSettings(TestTool::class, ['max_results' => '50']);
    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalId, [
        'api_key' => 'group-key',
        'max_results' => '77',
    ]);
    $svc->putPrincipalSettings(TestTool::class, $userPrincipalId, [
        'api_key' => 'user-key',
    ]);

    $effective = $svc->getEffectiveSettings(TestTool::class, $agentId, $userId);
    expect($effective['api_key'])->toBe('user-key')   // user > group
        ->and($effective['max_results'])->toBe('77');  // group still wins over global
});

test('cascade: multiple groups — later (higher-id) group wins over earlier; user wins last', function (): void {
    $svc = makeCascadeToolConfigService();
    $userId = (int) Capsule::table('users')->insertGetId([
        'email'      => 'multi-group@example.com',
        'username'   => 'multi_group',
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $userPrincipalId = (int) Capsule::table('principals')->insertGetId([
        'type' => Principal::TYPE_USER, 'user_id' => $userId,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $groupPrincipalIds = [];
    foreach (['G1', 'G2', 'G3'] as $name) {
        $gid = (int) Capsule::table('groups')->insertGetId([
            'name' => $name, 'description' => null, 'created_by_user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Capsule::table('group_memberships')->insert([
            'group_id' => $gid, 'user_id' => $userId, 'role' => GroupMembership::ROLE_MEMBER,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $groupPrincipalIds[] = (int) Capsule::table('principals')->insertGetId([
            'type' => Principal::TYPE_GROUP, 'group_id' => $gid,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    sort($groupPrincipalIds); // confirm the iteration order matches principal id (not group name)

    $agentId = makeTestAgentForUser($userId);

    $svc->putGlobalSettings(TestTool::class, ['max_results' => '50']);
    // Set a different `max_results` on every level
    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalIds[0], ['max_results' => 'g1']);
    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalIds[1], ['max_results' => 'g2']);
    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalIds[2], ['max_results' => 'g3']);
    $svc->putPrincipalSettings(TestTool::class, $userPrincipalId,    ['max_results' => 'user']);

    $effective = $svc->getEffectiveSettings(TestTool::class, $agentId, $userId);
    expect($effective['max_results'])->toBe('user'); // user > group[2] > group[1] > group[0] > global
});

test('cascade: agent override wins over everything, including the user-principal override', function (): void {
    $svc = makeCascadeToolConfigService();
    [$userId, $userPrincipalId, $groupPrincipalId] = setupGroupCascadeFixture(3);
    $agentId = makeTestAgentForUser($userId);

    $svc->putGlobalSettings(TestTool::class, ['max_results' => '50']);
    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalId, ['max_results' => 'g']);
    $svc->putPrincipalSettings(TestTool::class, $userPrincipalId,  ['max_results' => 'u']);
    $svc->putAgentOverride(TestTool::class, $agentId, ['max_results' => 'a']);

    expect($svc->getEffectiveSettings(TestTool::class, $agentId, $userId)['max_results'])->toBe('a');
});

test('cascade: stable ordering across repeated calls', function (): void {
    $svc = makeCascadeToolConfigService();
    [$userId] = setupGroupCascadeFixture(4);
    $agentId = makeTestAgentForUser($userId);

    $first  = $svc->getEffectiveSettings(TestTool::class, $agentId, $userId);
    $second = $svc->getEffectiveSettings(TestTool::class, $agentId, $userId);
    $third  = $svc->getEffectiveSettings(TestTool::class, $agentId, $userId);

    expect($second)->toBe($first)
        ->and($third)->toBe($first);
});

test('cascade source annotation: group keys are tagged "group", user keys "principal"', function (): void {
    $svc = makeCascadeToolConfigService();
    [$userId, $userPrincipalId, $groupPrincipalId] = setupGroupCascadeFixture(5);
    $agentId = makeTestAgentForUser($userId);

    $svc->putGlobalSettings(TestTool::class, ['max_results' => '50']);            // source: global
    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalId, ['api_key' => 'g']); // source: group
    $svc->putPrincipalSettings(TestTool::class, $userPrincipalId,  ['max_results' => 'u']); // source: principal

    $annotated = $svc->getEffectiveSettingsWithSource(TestTool::class, $agentId, $userId);

    expect($annotated['api_key']['source'])->toBe('group')     // only group has it
        ->and($annotated['api_key']['value'])->toBe('g')
        ->and($annotated['max_results']['source'])->toBe('principal') // user wins over global
        ->and($annotated['max_results']['value'])->toBe('u');
});

test('cascade source annotation: last write wins for source label too', function (): void {
    // A key set in BOTH group and user must show source=principal (the
    // LAST cascade iteration wins for both value and source label).
    $svc = makeCascadeToolConfigService();
    [$userId, $userPrincipalId, $groupPrincipalId] = setupGroupCascadeFixture(6);
    $agentId = makeTestAgentForUser($userId);

    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalId, ['api_key' => 'g']);
    $svc->putPrincipalSettings(TestTool::class, $userPrincipalId,  ['api_key' => 'u']);

    $annotated = $svc->getEffectiveSettingsWithSource(TestTool::class, $agentId, $userId);

    expect($annotated['api_key']['source'])->toBe('principal')
        ->and($annotated['api_key']['value'])->toBe('u');
});

test('PrincipalContext path bypasses group cascade (single explicit principal)', function (): void {
    // The explicit-PrincipalContext path is preserved as the "I know
    // exactly which principal to consult" seam — a future caller can
    // pass a group-principal context and only that group's settings
    // are read (no automatic group-cascade enumeration).
    $svc = makeCascadeToolConfigService();
    [$userId, $userPrincipalId, $groupPrincipalId] = setupGroupCascadeFixture(7);
    $agentId = makeTestAgentForUser($userId);

    $svc->putGlobalSettings(TestTool::class, ['max_results' => '50']);
    $svc->putPrincipalSettings(TestTool::class, $userPrincipalId,  ['api_key' => 'user']);
    $svc->putPrincipalSettings(TestTool::class, $groupPrincipalId, ['api_key' => 'group']);

    // Pass a PrincipalContext pinned to the GROUP principal. Only
    // group + global + agent are consulted (the user's settings must
    // NOT show up).
    $groupContext = new \Spora\Services\PrincipalContext(
        principalId: $groupPrincipalId,
        type: Principal::TYPE_GROUP,
        ownerUserId: null,
        runnerUserId: null,
    );

    $effective = $svc->getEffectiveSettings(TestTool::class, $agentId, $userId, $groupContext);
    expect($effective['api_key'])->toBe('group');
});
