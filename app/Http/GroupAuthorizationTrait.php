<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Models\GroupMembership;

/**
 * Shared per-group authorisation gate.
 *
 * `callerCanManageGroup()` is the locked rule for write access on the
 * group-settings pages: the caller is a global admin, or has the
 * `owner` / `admin` role inside the group. `member`-only callers see
 * the read-only view; non-members are 404-ed upstream by
 * `callerCanSeeGroup()` so existence-hiding is preserved.
 *
 * Lives as a trait because every group-settings controller
 * (GroupController, GroupMemberController, GroupPreferencesController,
 * GroupToolsController, GroupLlmConfigsController) needs both gates; a
 * trait keeps the lookup colocated with the rule instead of a
 * one-method service.
 */
trait GroupAuthorizationTrait
{
    /**
     * `true` when the caller can edit the group's settings pages:
     * global admin OR `role ∈ {owner, admin}` for the group. The
     * lookup uses the same role-tier strings
     * (`GroupMembership::ROLE_OWNER` / `ROLE_ADMIN`) that
     * {@see GroupService} writes.
     */
    protected function callerCanManageGroup(int $groupId, int $userId, AuthService $authService): bool
    {
        if ($authService->isAdmin()) {
            return true;
        }

        $role = Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->value('role');
        return $role === GroupMembership::ROLE_OWNER
            || $role === GroupMembership::ROLE_ADMIN;
    }

    /**
     * `true` when the caller can see the group at all: global admin OR
     * a row in `group_memberships` for this group. Used to make the
     * read paths existence-hiding (404 instead of 403) for non-members.
     */
    protected function callerCanSeeGroup(int $groupId, int $userId, AuthService $authService): bool
    {
        if ($authService->isAdmin()) {
            return true;
        }

        return Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->exists();
    }
}
