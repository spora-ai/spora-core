<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\Exceptions\GroupMembershipRuleException;
use Spora\Services\Exceptions\PrincipalHasDependentsException;

/**
 * RBAC + lifecycle for {@see Group} objects. The service is the single
 * author of `group_memberships.role` writes; controllers never mutate that
 * table directly so all role-tiers business rules stay in one place.
 *
 * Role tiers (highest → lowest):
 *   - `owner`  — can delete the group, change anyone's role (incl. adding
 *                an owner), remove anyone including other owners.
 *   - `admin`  — can add/remove `member` and `admin` role holders, change
 *                a non-owner member's role. Cannot promote to `owner` and
 *                cannot remove an `owner`.
 *   - `member` — read-only within the group's principal surface.
 *
 * Every mutating method checks caller authorisation against the group's
 * `group_memberships` table. System-admin (global) is delegated upstream
 * — the service models the principal axis only.
 *
 * Concurrency: every write opens a `lockForUpdate` lock on the group row
 * (or membership row) to prevent the kind of "demote the last owner"
 * races that would otherwise leave a group with no `owner`.
 */
final class GroupService
{
    private const DB_TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PrincipalService $principalService,
    ) {}

    /**
     * Create a new group. The creator is inserted as `role: owner` in
     * `group_memberships` and a group-principal is materialised in
     * `principals`. Both inserts share a single transaction.
     */
    public function createGroup(int $creatorUserId, string $name, ?string $description = null): Group
    {
        $self = $this;
        return Capsule::connection()->transaction(
            static function () use ($self, $creatorUserId, $name, $description): Group {
                $groupId = Capsule::table('groups')->insertGetId([
                    'name'              => $name,
                    'description'       => $description,
                    'created_by_user_id' => $creatorUserId,
                    'created_at'        => date(self::DB_TIMESTAMP_FORMAT),
                    'updated_at'        => date(self::DB_TIMESTAMP_FORMAT),
                ]);

                Capsule::table('group_memberships')->insert([
                    'group_id'   => $groupId,
                    'user_id'    => $creatorUserId,
                    'role'       => GroupMembership::ROLE_OWNER,
                    'joined_at'  => date(self::DB_TIMESTAMP_FORMAT),
                    'created_at' => date(self::DB_TIMESTAMP_FORMAT),
                    'updated_at' => date(self::DB_TIMESTAMP_FORMAT),
                ]);

                // Materialise the principal immediately so the group can
                // be referenced as an ownership target as soon as it exists.
                $self->principalService->ensureGroupPrincipal($groupId);

                return Group::findOrFail($groupId);
            },
        );
    }

    /**
     * Add a member to a group with the given role. Caller must be
     * `owner` or `admin` of the group. Adding at `owner` tier requires
     * `owner` role (admin cannot promote).
     *
     * @throws GroupMembershipRuleException When the caller lacks the role
     *         tier needed for the requested target role, or when the
     *         user is already a member.
     */
    public function addMember(int $groupId, int $userIdToAdd, string $role, int $callerUserId): void
    {
        if (!in_array($role, [GroupMembership::ROLE_OWNER, GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_MEMBER], true)) {
            throw new InvalidArgumentException("Unknown role: {$role}");
        }

        Capsule::connection()->transaction(
            function () use ($groupId, $userIdToAdd, $role, $callerUserId): void {
                Group::where('id', $groupId)->lockForUpdate()->first();
                $callerRole = self::fetchCallerRole($groupId, $callerUserId);

                self::assertCallerCanAddRole($callerRole, $role);
                self::assertNotAlreadyMember($groupId, $userIdToAdd);

                self::insertMembershipRow($groupId, $userIdToAdd, $role);
            },
        );
    }

    private static function assertCallerCanAddRole(?string $callerRole, string $targetRole): void
    {
        if ($targetRole === GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_OWNER) {
            throw new GroupMembershipRuleException('Only group owners can add owners.');
        }
        if ($callerRole !== GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_ADMIN) {
            throw new GroupMembershipRuleException(
                'Caller must be owner or admin to add a member.',
            );
        }
    }

    private static function assertNotAlreadyMember(int $groupId, int $userId): void
    {
        $existing = Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->first();
        if ($existing !== null) {
            throw new GroupMembershipRuleException(
                "User {$userId} is already a member of group {$groupId}.",
            );
        }
    }

    private static function insertMembershipRow(int $groupId, int $userId, string $role): void
    {
        Capsule::table('group_memberships')->insert([
            'group_id'   => $groupId,
            'user_id'    => $userId,
            'role'       => $role,
            'joined_at'  => date(self::DB_TIMESTAMP_FORMAT),
            'created_at' => date(self::DB_TIMESTAMP_FORMAT),
            'updated_at' => date(self::DB_TIMESTAMP_FORMAT),
        ]);
    }

    /**
     * Change a member's role. `owner → anything` requires `owner` caller;
     * `admin → member` and `member → admin` may also be done by an `admin`
     * caller; promoting to `owner` requires `owner` caller.
     *
     * @throws GroupMembershipRuleException When the caller is not `owner`
     *         and the requested change is not permitted at their tier, or
     *         when the change would remove the last `owner`.
     */
    public function changeMemberRole(int $groupId, int $memberUserId, string $newRole, int $callerUserId): void
    {
        if (!in_array($newRole, [GroupMembership::ROLE_OWNER, GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_MEMBER], true)) {
            throw new InvalidArgumentException("Unknown role: {$newRole}");
        }

        Capsule::connection()->transaction(
            function () use ($groupId, $memberUserId, $newRole, $callerUserId): void {
                Group::where('id', $groupId)->lockForUpdate()->first();

                $callerRole = self::fetchCallerRole($groupId, $callerUserId);
                if ($callerRole === null) {
                    throw new GroupMembershipRuleException('Caller is not a member of the group.');
                }

                self::assertCallerCanChangeRole($callerRole, $newRole);
                $existing = self::fetchMembershipRow($groupId, $memberUserId);
                if ($existing === null) {
                    throw new GroupMembershipRuleException(
                        "User {$memberUserId} is not a member of group {$groupId}.",
                    );
                }

                self::assertNotDemotingLastOwner($groupId, $existing->role, $newRole);

                self::updateMembershipRole($groupId, $memberUserId, $newRole);
            },
        );
    }

    private static function fetchMembershipRow(int $groupId, int $userId): ?object
    {
        return Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->first();
    }

    private static function updateMembershipRole(int $groupId, int $userId, string $role): void
    {
        Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->update([
                'role'       => $role,
                'updated_at' => date(self::DB_TIMESTAMP_FORMAT),
            ]);
    }

    private static function assertCallerCanChangeRole(string $callerRole, string $newRole): void
    {
        if ($newRole === GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_OWNER) {
            throw new GroupMembershipRuleException('Only owners can promote to owner.');
        }
        if ($callerRole !== GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_ADMIN) {
            throw new GroupMembershipRuleException(
                'Caller must be owner or admin to change roles.',
            );
        }
    }

    private static function assertNotDemotingLastOwner(int $groupId, string $currentRole, string $newRole): void
    {
        if ($currentRole !== GroupMembership::ROLE_OWNER || $newRole === GroupMembership::ROLE_OWNER) {
            return;
        }
        $ownerCount = self::countOwners($groupId);
        if ($ownerCount <= 1) {
            throw new GroupMembershipRuleException(
                'Cannot demote the last owner of the group.',
            );
        }
    }

    private static function countOwners(int $groupId): int
    {
        return (int) Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('role', GroupMembership::ROLE_OWNER)
            ->count();
    }

    /**
     * Remove a member from a group. Refuses to remove the last `owner`.
     *
     * @throws GroupMembershipRuleException When the caller lacks authority,
     *         when the target is the last owner, or when the caller is
     *         the target at non-owner tier (admins cannot self-evict).
     */
    public function removeMember(int $groupId, int $userId, int $callerUserId): void
    {
        Capsule::connection()->transaction(
            function () use ($groupId, $userId, $callerUserId): void {
                Group::where('id', $groupId)->lockForUpdate()->first();

                $callerRole = self::fetchCallerRole($groupId, $callerUserId);
                if ($callerRole === null) {
                    throw new GroupMembershipRuleException('Caller is not a member of the group.');
                }

                $target = self::fetchMembershipRow($groupId, $userId);
                if ($target === null) {
                    return; // idempotent
                }

                self::assertCallerCanRemoveMember($groupId, $callerRole, $target->role);
                self::deleteMembershipRow($groupId, $userId);
            },
        );
    }

    private static function assertCallerCanRemoveMember(int $groupId, string $callerRole, string $targetRole): void
    {
        if ($targetRole === GroupMembership::ROLE_OWNER) {
            self::assertCallerCanRemoveOwner($groupId, $callerRole);
            return;
        }
        if ($callerRole !== GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_ADMIN) {
            throw new GroupMembershipRuleException(
                'Caller must be owner or admin to remove members.',
            );
        }
    }

    private static function assertCallerCanRemoveOwner(int $groupId, string $callerRole): void
    {
        $ownerCount = Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('role', GroupMembership::ROLE_OWNER)
            ->count();
        if ($ownerCount <= 1) {
            throw new GroupMembershipRuleException(
                'Cannot remove the last owner of the group.',
            );
        }
        if ($callerRole !== GroupMembership::ROLE_OWNER) {
            throw new GroupMembershipRuleException(
                'Only owners can remove other owners.',
            );
        }
    }

    private static function deleteMembershipRow(int $groupId, int $userId): void
    {
        Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * Delete a group. Route is gated by AdminMiddleware (see
     * RouteDefinitions::ROUTE_GROUPS_ID), so the caller is either a
     * global admin or an `owner` of the group. Non-owner non-admin
     * callers are refused so the service still holds its own gate —
     * callers that happen to bypass the middleware (tests, future
     * bulk-import paths) can't delete groups they don't control.
     *
     * Pre-flight: refuse if any agent still references the group's
     * principal; the caller surfaces a 409 with a `reassign_endpoint`
     * hint to the operator.
     *
     * @throws GroupMembershipRuleException When the caller is not admin
     *         and is not the group owner
     * @throws PrincipalHasDependentsException When the group still owns agents
     */
    public function deleteGroup(int $groupId, int $callerUserId, bool $isAdmin = false): void
    {
        if (!$isAdmin) {
            $callerRole = self::fetchCallerRole($groupId, $callerUserId);
            if ($callerRole !== GroupMembership::ROLE_OWNER) {
                throw new GroupMembershipRuleException('Only an owner or a global admin can delete the group.');
            }
        }

        $principal = $this->principalService->principalForGroup($groupId);
        if ($principal === null) {
            // No principal — nothing to guard. Drop the group.
            Capsule::table('groups')->where('id', $groupId)->delete();
            return;
        }

        $dependentIds = $this->principalService->dependentAgentIds([(int) $principal->id]);
        if ($dependentIds !== []) {
            throw new PrincipalHasDependentsException(
                "Group {$groupId} cannot be deleted while it owns " . count($dependentIds) . ' agent(s).',
                $dependentIds,
            );
        }

        // Re-check the dependent count + lock the group row inside a
        // transaction so two parallel deletes (e.g. one from the
        // operator UI and one from a cron-driven cleanup) can't both
        // observe an empty dependents list and try to delete the same
        // row twice.
        Capsule::connection()->transaction(
            static function () use ($groupId, $principal): void {
                Group::where('id', $groupId)->lockForUpdate()->first();
                $recheck = Capsule::table('agents')
                    ->where('principal_id', $principal->id)
                    ->count();
                if ($recheck > 0) {
                    throw new PrincipalHasDependentsException(
                        "Group {$groupId} cannot be deleted while it owns agents (race detected).",
                        [],
                    );
                }
                Capsule::table('principals')->where('id', $principal->id)->delete();
                Capsule::table('groups')->where('id', $groupId)->delete();
            },
        );
    }

    /**
     * Look up the caller's role within a group. Returns null if the
     * caller is not a member. Used by every authorisation gate in this
     * service.
     */
    public static function fetchCallerRole(int $groupId, int $userId): ?string
    {
        $role = Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->value('role');

        return $role !== null ? (string) $role : null;
    }
}
