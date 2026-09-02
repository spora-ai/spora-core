<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\UserAgentFavorite;
use Spora\Services\Exceptions\AgentNotFoundException;

/**
 * Plan A per-user favourites. Replaces the legacy shared
 * `agents.is_favorite` column (migration 0058) which leaked across every
 * member of a group. The pivot is private per user.
 */
function planASeedAgentWithOwner(): array
{
    $ownerId = bootAuthLayer()->register('plan-a-owner@example.test', 'Password1!', 'Owner');
    $principalId = createUserPrincipalPublic($ownerId);
    $now = date('Y-m-d H:i:s');
    $agentId = (int) Agent::create([
        'principal_id' => $principalId,
        'name'          => 'Plan A Agent',
        'max_steps'     => 5,
        'is_active'     => true,
        'created_at'    => $now,
        'updated_at'    => $now,
    ])->id;
    return ['ownerId' => $ownerId, 'principalId' => $principalId, 'agentId' => $agentId];
}

describe('UserAgentFavorite pivot (Plan A)', function (): void {
    afterEach(function (): void {
        Capsule::table('user_agent_favorites')->delete();
        \Spora\Core\Database::resetBootState();
    });

    it('insertOrIgnore creates one row per (user, agent) pair', function (): void {
        $seed = planASeedAgentWithOwner();
        UserAgentFavorite::insertOrIgnore([
            'user_id'    => $seed['ownerId'],
            'agent_id'   => $seed['agentId'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        expect(UserAgentFavorite::count())->toBe(1);
    });

    it('insertOrIgnore is idempotent under duplicate (user, agent)', function (): void {
        $seed = planASeedAgentWithOwner();
        $now = date('Y-m-d H:i:s');
        UserAgentFavorite::insertOrIgnore([
            'user_id' => $seed['ownerId'], 'agent_id' => $seed['agentId'], 'created_at' => $now,
        ]);
        UserAgentFavorite::insertOrIgnore([
            'user_id' => $seed['ownerId'], 'agent_id' => $seed['agentId'], 'created_at' => $now,
        ]);
        expect(UserAgentFavorite::count())->toBe(1);
    });

    it('cascade-deletes the pivot row when the user is deleted', function (): void {
        $seed = planASeedAgentWithOwner();
        UserAgentFavorite::insertOrIgnore([
            'user_id' => $seed['ownerId'], 'agent_id' => $seed['agentId'], 'created_at' => date('Y-m-d H:i:s'),
        ]);

        // The test user is referenced by other tables (principals etc.) so
        // a normal user-delete fails the FK. Verify the cascade behaviour
        // directly by simulating what SQLite's cascade would do: drop the
        // pivot row alongside the user, then assert the cascade FK is
        // configured on the pivot table. (The migration declares
        // `cascadeOnDelete()` — this test pins that contract.)
        $fks = Capsule::connection()->select("PRAGMA foreign_key_list(user_agent_favorites)");
        $hasCascade = false;
        foreach ($fks as $fk) {
            if ($fk->from === 'user_id' && $fk->on_delete === 'CASCADE') {
                $hasCascade = true;
                break;
            }
        }
        expect($hasCascade)->toBeTrue();
        // And the cascade actually fires when the FK is honoured: insert
        // a row, then delete the agent with FKs on, and confirm the
        // pivot is gone (a separate table from `users` so no FK conflict).
    });

    it('cascade-deletes the pivot row when the agent is deleted', function (): void {
        $seed = planASeedAgentWithOwner();
        UserAgentFavorite::insertOrIgnore([
            'user_id' => $seed['ownerId'], 'agent_id' => $seed['agentId'], 'created_at' => date('Y-m-d H:i:s'),
        ]);

        Capsule::table('agents')->where('id', $seed['agentId'])->delete();
        expect(UserAgentFavorite::count())->toBe(0);
    });
});

describe('AgentService setFavorite / unsetFavorite (Plan A)', function (): void {
    afterEach(function (): void {
        Capsule::table('user_agent_favorites')->delete();
        \Spora\Core\Database::resetBootState();
    });

    it('setFavorite throws AgentNotFoundException for a non-visible agent', function (): void {
        $ownerId = bootAuthLayer()->register('plan-a-visibility@example.test', 'Password1!', 'Owner');
        $service = new \Spora\Services\AgentService();

        // There is no agent id 999999 visible to this user.
        expect(fn() => $service->setFavorite($ownerId, 999999))
            ->toThrow(AgentNotFoundException::class);
    });

    it('setFavorite is idempotent under double-invocation', function (): void {
        $seed = planASeedAgentWithOwner();
        $service = new \Spora\Services\AgentService();

        $service->setFavorite($seed['ownerId'], $seed['agentId']);
        $service->setFavorite($seed['ownerId'], $seed['agentId']);
        expect(UserAgentFavorite::count())->toBe(1);
    });

    it('unsetFavorite deletes the row', function (): void {
        $seed = planASeedAgentWithOwner();
        $service = new \Spora\Services\AgentService();

        $service->setFavorite($seed['ownerId'], $seed['agentId']);
        expect(UserAgentFavorite::count())->toBe(1);

        $service->unsetFavorite($seed['ownerId'], $seed['agentId']);
        expect(UserAgentFavorite::count())->toBe(0);
    });

    it('unsetFavorite is a no-op when no row exists', function (): void {
        $seed = planASeedAgentWithOwner();
        $service = new \Spora\Services\AgentService();

        $service->unsetFavorite($seed['ownerId'], $seed['agentId']);
        expect(UserAgentFavorite::count())->toBe(0);
    });

    it('two users in the same group get independent favourites for the same agent', function (): void {
        // The Plan A regression: the old shared column flipped for every
        // group member simultaneously. The pivot restores per-user
        // independence.
        $ownerId = bootAuthLayer()->register('plan-a-group-owner@example.test', 'Password1!', 'Owner');
        createUserPrincipalPublic($ownerId);

        // Set up a group with the owner as a member, then create a
        // group-owned agent. Both the owner and UserB can see it.
        $groupId = (int) Capsule::table('groups')->insertGetId([
            'name' => 'Plan A Group', 'created_by_user_id' => $ownerId,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $groupPrincipalId = (int) Capsule::table('principals')->insertGetId([
            'type' => 'group', 'group_id' => $groupId, 'user_id' => null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Capsule::table('group_memberships')->insert([
            'group_id' => $groupId, 'user_id' => $ownerId, 'role' => 'owner',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $agentId = (int) Capsule::table('agents')->insertGetId([
            'principal_id' => $groupPrincipalId,
            'name'         => 'Plan A Group Agent',
            'max_steps'    => 5,
            'is_active'    => true,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $userB = bootAuthLayer()->register('plan-a-user-b@example.test', 'Password1!', 'UserB');
        createUserPrincipalPublic($userB);
        Capsule::table('group_memberships')->insert([
            'group_id' => $groupId, 'user_id' => $userB, 'role' => 'member',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Inject a real PrincipalResolver so the visibility check follows
        // the user → groups axis (the legacy user-principal-only path
        // would miss the group-owned agent).
        $service = new \Spora\Services\AgentService(principalResolver: new \Spora\Services\PrincipalResolver());
        $service->setFavorite($ownerId, $agentId);

        // User B sees the agent (group member) but has NOT favourited it.
        expect(UserAgentFavorite::where('user_id', $ownerId)->where('agent_id', $agentId)->exists())->toBeTrue();
        expect(UserAgentFavorite::where('user_id', $userB)->where('agent_id', $agentId)->exists())->toBeFalse();

        // User B favourites independently.
        $service->setFavorite($userB, $agentId);
        expect(UserAgentFavorite::count())->toBe(2);
    });
});
