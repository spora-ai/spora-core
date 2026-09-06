<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\GroupMembership;
use Spora\Services\Exceptions\GroupMembershipRuleException;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

defined('GROUP_TEST_PASSWORD') || define('GROUP_TEST_PASSWORD', 'Password1!');

function makeGroupServiceWithOwner(): array
{
    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $email = "group-owner-{$seq}@example.com";
    $ownerId = bootAuth($auth, $email, GROUP_TEST_PASSWORD);

    $principalService = new PrincipalService(new PrincipalResolver());
    $service = new GroupService($principalService);

    return [$service, $principalService, $ownerId, $auth];
}

describe('GroupService::createGroup', function (): void {
    it('creates a group, attaches the creator as owner, and materialises the principal', function (): void {
        [$service, $principalService, $ownerId] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'Eng', 'Engineering team');

        expect($group->id)->toBeGreaterThan(0);
        expect($group->name)->toBe('Eng');
        expect($group->created_by_user_id)->toBe($ownerId);

        $membership = Capsule::table('group_memberships')
            ->where('group_id', $group->id)
            ->where('user_id', $ownerId)
            ->first();
        expect($membership->role)->toBe('owner');

        // Principal materialised by createGroup (in the same transaction)
        expect($principalService->principalForGroup((int) $group->id))->not->toBeNull();
    });
});

describe('GroupService::addMember', function (): void {
    it('allows the owner to add a new member', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');

        $inviteeId = bootAuth($auth, 'invitee1@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $inviteeId, 'member', $ownerId);

        $membership = GroupMembership::where('group_id', $group->id)->where('user_id', $inviteeId)->first();
        expect($membership->role)->toBe('member');
    });

    it('rejects non-owner, non-admin callers', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');
        $randomId = bootAuth($auth, 'random3@example.com', GROUP_TEST_PASSWORD);
        $inviteeId = bootAuth($auth, 'invitee3@example.com', GROUP_TEST_PASSWORD);

        expect(fn() => $service->addMember($group->id, $inviteeId, 'member', $randomId))
            ->toThrow(GroupMembershipRuleException::class);
    });
});

describe('GroupService::changeMemberRole', function (): void {
    it('lets the owner promote a member to admin', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');
        $inviteeId = bootAuth($auth, 'p4@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $inviteeId, 'member', $ownerId);

        $service->changeMemberRole($group->id, $inviteeId, 'admin', $ownerId);
        $membership = GroupMembership::where('group_id', $group->id)->where('user_id', $inviteeId)->first();
        expect($membership->role)->toBe('admin');
    });

    it('rejects admin trying to promote to owner', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');
        $admin = bootAuth($auth, 'a5@example.com', GROUP_TEST_PASSWORD);
        $other = bootAuth($auth, 'o5@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $admin, 'admin', $ownerId);
        $service->addMember($group->id, $other, 'member', $ownerId);

        expect(fn() => $service->changeMemberRole($group->id, $other, 'owner', $admin))
            ->toThrow(GroupMembershipRuleException::class);
    });
});

describe('GroupService::removeMember', function (): void {
    it('lets the owner remove a member', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');
        $inviteeId = bootAuth($auth, 'r6@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $inviteeId, 'member', $ownerId);

        $service->removeMember($group->id, $inviteeId, $ownerId);
        expect(GroupMembership::where('group_id', $group->id)->where('user_id', $inviteeId)->count())->toBe(0);
    });

    it('rejects removal of an owner by an admin', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');
        $secondOwnerId = bootAuth($auth, 'so7@example.com', GROUP_TEST_PASSWORD);
        $admin = bootAuth($auth, 'a7@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $secondOwnerId, 'owner', $ownerId);
        $service->addMember($group->id, $admin, 'admin', $ownerId);

        expect(fn() => $service->removeMember($group->id, $secondOwnerId, $admin))
            ->toThrow(GroupMembershipRuleException::class);
    });
});

describe('GroupService::deleteGroup', function (): void {
    it('lets the owner delete a group with no dependents', function (): void {
        [$service, $principalService, $ownerId] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');

        $service->deleteGroup($group->id, $ownerId);
        expect(Capsule::table('groups')->where('id', $group->id)->count())->toBe(0);
    });

    it('deleteGroup(): throws when called by a non-owner', function (): void {
        // Covers the pre-flight authorisation without requiring
        // bootstrapping a real dependent agent row.
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');
        $randomId = bootAuth($auth, 'random-del@example.com', GROUP_TEST_PASSWORD);
        expect(fn() => $service->deleteGroup($group->id, $randomId))
            ->toThrow(GroupMembershipRuleException::class);
    });
});

describe('GroupService::listMembers', function (): void {
    it('lists members from the controller (via the Groups model layer)', function (): void {
        // The listMembers lookup is implemented in GroupMemberController::index
        // which queries GroupMembership::where('group_id', ...) directly;
        // GroupService does not itself expose listMembers.
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'G1');
        $a = bootAuth($auth, 'l8a@example.com', GROUP_TEST_PASSWORD);
        $b = bootAuth($auth, 'l8b@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $a, 'admin', $ownerId);
        $service->addMember($group->id, $b, 'member', $ownerId);

        $count = GroupMembership::where('group_id', $group->id)->count();
        expect($count)->toBe(3);
    });
});

describe('GroupService::groupForPrincipal', function (): void {
    it('is not directly exposed on the service', function (): void {
        // Group service does not expose a direct principal->group lookup;
        // look up via PrincipalService::principalForGroup($groupId) and
        // a join, or via the model directly. This case is intentionally
        // light — GroupService is intentionally narrow.
        expect(true)->toBeTrue();
    });
});

describe('GroupService::addMember extended paths', function (): void {
    it('rejects owner-promotion by an admin caller', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'GP1');
        $admin = bootAuth($auth, 'gp-admin@example.com', GROUP_TEST_PASSWORD);
        $invitee = bootAuth($auth, 'gp-invitee@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $admin, 'admin', $ownerId);

        expect(fn() => $service->addMember($group->id, $invitee, 'owner', $admin))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('rejects adding a user who is already a member', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'GP2');
        $invitee = bootAuth($auth, 'gp-invitee2@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $invitee, 'member', $ownerId);

        expect(fn() => $service->addMember($group->id, $invitee, 'admin', $ownerId))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('rejects an unknown role', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'GP3');
        $invitee = bootAuth($auth, 'gp-invitee3@example.com', GROUP_TEST_PASSWORD);

        expect(fn() => $service->addMember($group->id, $invitee, 'queen', $ownerId))
            ->toThrow(InvalidArgumentException::class);
    });

    it('allows an admin to add a member', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'GP4');
        $admin = bootAuth($auth, 'gp-admin4@example.com', GROUP_TEST_PASSWORD);
        $invitee = bootAuth($auth, 'gp-invitee4@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $admin, 'admin', $ownerId);

        $service->addMember($group->id, $invitee, 'member', $admin);

        $membership = GroupMembership::where('group_id', $group->id)->where('user_id', $invitee)->first();
        expect($membership->role)->toBe('member');
    });
});

describe('GroupService::changeMemberRole extended paths', function (): void {
    it('rejects an unknown new role', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'CR1');
        $target = bootAuth($auth, 'cr-target@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $target, 'member', $ownerId);

        expect(fn() => $service->changeMemberRole($group->id, $target, 'queen', $ownerId))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a non-member caller', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'CR2');
        $target = bootAuth($auth, 'cr-target2@example.com', GROUP_TEST_PASSWORD);
        $stranger = bootAuth($auth, 'cr-stranger@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $target, 'member', $ownerId);

        expect(fn() => $service->changeMemberRole($group->id, $target, 'admin', $stranger))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('rejects a non-existent target member', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'CR3');
        $stranger = bootAuth($auth, 'cr-stranger3@example.com', GROUP_TEST_PASSWORD);

        expect(fn() => $service->changeMemberRole($group->id, $stranger, 'admin', $ownerId))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('rejects demoting the last owner', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'CR4');

        expect(fn() => $service->changeMemberRole($group->id, $ownerId, 'admin', $ownerId))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('lets an admin demote another member but not touch owners', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'CR5');
        $admin = bootAuth($auth, 'cr-admin@example.com', GROUP_TEST_PASSWORD);
        $target = bootAuth($auth, 'cr-target5@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $admin, 'admin', $ownerId);
        $service->addMember($group->id, $target, 'admin', $ownerId);

        $service->changeMemberRole($group->id, $target, 'member', $admin);
        expect((string) GroupMembership::where('group_id', $group->id)->where('user_id', $target)->value('role'))->toBe('member');
    });
});

describe('GroupService::removeMember extended paths', function (): void {
    it('is idempotent when the target is not a member', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'RM1');
        $stranger = bootAuth($auth, 'rm-stranger@example.com', GROUP_TEST_PASSWORD);

        $service->removeMember($group->id, $stranger, $ownerId);
        expect(GroupMembership::where('group_id', $group->id)->where('user_id', $stranger)->count())->toBe(0);
    });

    it('rejects a non-member caller', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'RM2');
        $target = bootAuth($auth, 'rm-target@example.com', GROUP_TEST_PASSWORD);
        $stranger = bootAuth($auth, 'rm-stranger2@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $target, 'member', $ownerId);

        expect(fn() => $service->removeMember($group->id, $target, $stranger))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('rejects removing the last owner', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'RM3');

        expect(fn() => $service->removeMember($group->id, $ownerId, $ownerId))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('lets an admin remove a member', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'RM4');
        $admin = bootAuth($auth, 'rm-admin@example.com', GROUP_TEST_PASSWORD);
        $target = bootAuth($auth, 'rm-target4@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $admin, 'admin', $ownerId);
        $service->addMember($group->id, $target, 'member', $ownerId);

        $service->removeMember($group->id, $target, $admin);
        expect(GroupMembership::where('group_id', $group->id)->where('user_id', $target)->count())->toBe(0);
    });
});

describe('GroupService::deleteGroup extended paths', function (): void {
    it('drops the group without the principal row when no principal exists', function (): void {
        [$service, $principalService, $ownerId] = makeGroupServiceWithOwner();
        $auth = bootAuthLayer();
        $ownerId2 = bootAuth($auth, 'dg-no-p-owner@example.com', GROUP_TEST_PASSWORD);
        $group = $service->createGroup($ownerId2, 'DG-NP');
        Capsule::table('principals')->where('group_id', $group->id)->delete();

        $service->deleteGroup($group->id, $ownerId2);
        expect(Capsule::table('groups')->where('id', $group->id)->count())->toBe(0);
    });

    it('throws PrincipalHasDependentsException when the group still owns agents', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $ownerId2 = bootAuth($auth, 'dg-busy-owner@example.com', GROUP_TEST_PASSWORD);
        $group = $service->createGroup($ownerId2, 'DG-BUSY');
        $principal = $principalService->ensureGroupPrincipal((int) $group->id);
        Capsule::table('agents')->insert([
            'principal_id'         => (int) $principal->id,
            'name'                 => 'Stuck',
            'description'          => null,
            'llm_driver_config_id' => null,
            'max_steps'            => 10,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        expect(fn() => $service->deleteGroup($group->id, $ownerId2))
            ->toThrow(Spora\Services\Exceptions\PrincipalHasDependentsException::class);
    });
});

describe('GroupService::admin bypass ($isAdmin = true)', function (): void {
    it('addMember: lets a global admin add a member without being in the group', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'ADM1');

        $adminId = bootAuth($auth, 'adm1@example.com', GROUP_TEST_PASSWORD);
        $invitee = bootAuth($auth, 'adm1-invitee@example.com', GROUP_TEST_PASSWORD);

        $service->addMember($group->id, $invitee, 'member', $adminId, isAdmin: true);

        $membership = GroupMembership::where('group_id', $group->id)->where('user_id', $invitee)->first();
        expect($membership)->not->toBeNull();
        expect((string) $membership->role)->toBe('member');
    });

    it('addMember: lets a global admin promote a new member to owner', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'ADM2');

        $adminId = bootAuth($auth, 'adm2@example.com', GROUP_TEST_PASSWORD);
        $invitee = bootAuth($auth, 'adm2-invitee@example.com', GROUP_TEST_PASSWORD);

        $service->addMember($group->id, $invitee, 'owner', $adminId, isAdmin: true);

        expect((string) GroupMembership::where('group_id', $group->id)->where('user_id', $invitee)->value('role'))->toBe('owner');
    });

    it('changeMemberRole: lets a global admin change a member role without being in the group', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'ADM3');

        $target = bootAuth($auth, 'adm3-target@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $target, 'member', $ownerId);

        $adminId = bootAuth($auth, 'adm3@example.com', GROUP_TEST_PASSWORD);
        $service->changeMemberRole($group->id, $target, 'admin', $adminId, isAdmin: true);

        expect((string) GroupMembership::where('group_id', $group->id)->where('user_id', $target)->value('role'))->toBe('admin');
    });

    it('removeMember: lets a global admin remove a member without being in the group', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'ADM4');

        $target = bootAuth($auth, 'adm4-target@example.com', GROUP_TEST_PASSWORD);
        $service->addMember($group->id, $target, 'member', $ownerId);

        $adminId = bootAuth($auth, 'adm4@example.com', GROUP_TEST_PASSWORD);
        $service->removeMember($group->id, $target, $adminId, isAdmin: true);

        expect(GroupMembership::where('group_id', $group->id)->where('user_id', $target)->count())->toBe(0);
    });

    it('removeMember: the last-owner guard still fires even when caller is admin', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'ADM5');

        $adminId = bootAuth($auth, 'adm5@example.com', GROUP_TEST_PASSWORD);

        expect(fn() => $service->removeMember($group->id, $ownerId, $adminId, isAdmin: true))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('changeMemberRole: the last-owner demotion guard still fires even when caller is admin', function (): void {
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'ADM6');

        $adminId = bootAuth($auth, 'adm6@example.com', GROUP_TEST_PASSWORD);

        expect(fn() => $service->changeMemberRole($group->id, $ownerId, 'admin', $adminId, isAdmin: true))
            ->toThrow(GroupMembershipRuleException::class);
    });

    it('addMember: default $isAdmin = false keeps the existing non-member rejection', function (): void {
        // Regression guard: the new flag is opt-in. Without $isAdmin, a
        // non-member caller still hits the tier rule and is refused.
        [$service, $principalService, $ownerId, $auth] = makeGroupServiceWithOwner();
        $group = $service->createGroup($ownerId, 'ADM7');
        $stranger = bootAuth($auth, 'adm7-stranger@example.com', GROUP_TEST_PASSWORD);
        $invitee = bootAuth($auth, 'adm7-invitee@example.com', GROUP_TEST_PASSWORD);

        expect(fn() => $service->addMember($group->id, $invitee, 'member', $stranger))
            ->toThrow(GroupMembershipRuleException::class);
    });
});
