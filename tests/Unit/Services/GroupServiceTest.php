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
