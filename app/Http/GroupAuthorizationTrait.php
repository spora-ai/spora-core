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
 * {@see GroupController::callerCanSeeGroup()} so existence-hiding is
 * preserved.
 *
 * Lives as a trait (rather than a static method on {@see GroupController})
 * because the three new group-settings controllers
 * ({@see GroupPreferencesController}, {@see GroupToolsController},
 * {@see GroupLlmConfigsController}) all need it; a trait keeps the
 * lookup colocated with the rule instead of a one-method service.
 */
trait GroupAuthorizationTrait
{
    /**
     * Single-row read of `group_memberships.role`. Cached per-request
     * so the auth check on every endpoint hits the cache, not the DB.
     * The cache is keyed by `$userId.$groupId` so the trait stays safe
     * to call from any controller in the same request lifecycle.
     *
     * @var array<string, string|null>
     */
    private static array $roleCache = [];

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

        $cacheKey = $userId . '.' . $groupId;
        if (!array_key_exists($cacheKey, self::$roleCache)) {
            $role = Capsule::table('group_memberships')
                ->where('group_id', $groupId)
                ->where('user_id', $userId)
                ->value('role');
            self::$roleCache[$cacheKey] = $role !== null ? (string) $role : null;
        }

        $cached = self::$roleCache[$cacheKey];
        return $cached === GroupMembership::ROLE_OWNER
            || $cached === GroupMembership::ROLE_ADMIN;
    }
}
