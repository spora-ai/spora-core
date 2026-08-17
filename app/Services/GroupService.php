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
                    'created_at'        => date('Y-m-d H:i:s'),
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);

                Capsule::table('group_memberships')->insert([
                    'group_id'   => $groupId,
                    'user_id'    => $creatorUserId,
                    'role'       => GroupMembership::ROLE_OWNER,
                    'joined_at'  => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
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
            static function () use ($groupId, $userIdToAdd, $role, $callerUserId): void {
                Group::where('id', $groupId)->lockForUpdate()->first();

                $callerRole = static::fetchCallerRole($groupId, $callerUserId);

                if ($role === GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_OWNER) {
                    throw new GroupMembershipRuleException(
                        'Only group owners can add owners.',
                    );
                }

                if ($callerRole !== GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_ADMIN) {
                    throw new GroupMembershipRuleException(
                        'Caller must be owner or admin to add a member.',
                    );
                }

                $existing = Capsule::table('group_memberships')
                    ->where('group_id', $groupId)
                    ->where('user_id', $userIdToAdd)
                    ->first();
                if ($existing !== null) {
                    throw new GroupMembershipRuleException(
                        "User {$userIdToAdd} is already a member of group {$groupId}.",
                    );
                }

                Capsule::table('group_memberships')->insert([
                    'group_id'   => $groupId,
                    'user_id'    => $userIdToAdd,
                    'role'       => $role,
                    'joined_at'  => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            },
        );
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
            static function () use ($groupId, $memberUserId, $newRole, $callerUserId): void {
                Group::where('id', $groupId)->lockForUpdate()->first();

                $callerRole = static::fetchCallerRole($groupId, $callerUserId);
                if ($callerRole === null) {
                    throw new GroupMembershipRuleException('Caller is not a member of the group.');
                }

                if ($newRole === GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_OWNER) {
                    throw new GroupMembershipRuleException('Only owners can promote to owner.');
                }

                if ($callerRole !== GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_ADMIN) {
                    throw new GroupMembershipRuleException(
                        'Caller must be owner or admin to change roles.',
                    );
                }

                $existing = Capsule::table('group_memberships')
                    ->where('group_id', $groupId)
                    ->where('user_id', $memberUserId)
                    ->first();
                if ($existing === null) {
                    throw new GroupMembershipRuleException(
                        "User {$memberUserId} is not a member of group {$groupId}.",
                    );
                }

                // Refuse to demote the last owner.
                if ($existing->role === GroupMembership::ROLE_OWNER && $newRole !== GroupMembership::ROLE_OWNER) {
                    $ownerCount = Capsule::table('group_memberships')
                        ->where('group_id', $groupId)
                        ->where('role', GroupMembership::ROLE_OWNER)
                        ->count();
                    if ($ownerCount <= 1) {
                        throw new GroupMembershipRuleException(
                            'Cannot demote the last owner of the group.',
                        );
                    }
                }

                Capsule::table('group_memberships')
                    ->where('group_id', $groupId)
                    ->where('user_id', $memberUserId)
                    ->update([
                        'role'       => $newRole,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            },
        );
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
            static function () use ($groupId, $userId, $callerUserId): void {
                Group::where('id', $groupId)->lockForUpdate()->first();

                $callerRole = static::fetchCallerRole($groupId, $callerUserId);
                if ($callerRole === null) {
                    throw new GroupMembershipRuleException('Caller is not a member of the group.');
                }

                $target = Capsule::table('group_memberships')
                    ->where('group_id', $groupId)
                    ->where('user_id', $userId)
                    ->first();
                if ($target === null) {
                    return; // idempotent
                }

                if ($target->role === GroupMembership::ROLE_OWNER) {
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
                } elseif ($callerRole !== GroupMembership::ROLE_OWNER && $callerRole !== GroupMembership::ROLE_ADMIN) {
                    throw new GroupMembershipRuleException(
                        'Caller must be owner or admin to remove members.',
                    );
                }

                Capsule::table('group_memberships')
                    ->where('group_id', $groupId)
                    ->where('user_id', $userId)
                    ->delete();
            },
        );
    }

    /**
     * Delete a group. Pre-flight: refuse if any agent still references the
     * group's principal; the caller should surface a 409 with a
     * `reassign_endpoint` hint to the operator.
     *
     * @throws GroupMembershipRuleException When the caller is not the owner
     * @throws PrincipalHasDependentsException When the group still owns agents
     */
    public function deleteGroup(int $groupId, int $callerUserId): void
    {
        // Caller must be an owner. Admin cannot delete.
        $callerRole = self::fetchCallerRole($groupId, $callerUserId);
        if ($callerRole !== GroupMembership::ROLE_OWNER) {
            throw new GroupMembershipRuleException('Only an owner can delete the group.');
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

        Capsule::connection()->transaction(
            static function () use ($groupId, $principal): void {
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
