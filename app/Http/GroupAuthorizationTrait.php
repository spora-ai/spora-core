<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Shared per-group authorisation gate and request-resolution helpers.
 *
 * Two distinct read rules live here:
 * - `callerCanSeeGroup()` is membership-only: only callers with a row in
 *   `group_memberships` for the group can read its detail page (show,
 *   agents, preferences, llm-configs, tools). Global admins without
 *   membership are 404-ed so existence-hiding is preserved; they manage
 *   members via the admin-panel overlay, which has its own dedicated
 *   read rule in `GroupMemberController::callerCanReadGroup()`.
 * - `callerCanManageGroup()` keeps the global-admin bypass for write
 *   access on the group-settings pages: global admin OR
 *   `role ∈ {owner, admin}` for the group.
 *
 * The four `resolve*()` / `requireCurrentUserIdOrFail()` /
 * `loadGroupPrincipalIfVisible()` helpers compose the canonical
 * "401 → 404 → principal" short-circuit chain every group-settings
 * controller needs. They live on the trait (rather than a base class
 * or service) because they cross the boundary between HTTP response
 * shaping (`JsonResponse` short-circuits via `JsonControllerHelpers`)
 * and a small slice of business lookup (`principalForGroup`), which
 * keeps the controllers reading as a flat list of actions.
 *
 * Lives as a trait because every group-settings controller needs the
 * full set; a trait keeps the lookup colocated with the rules instead
 * of a one-method service. `AuthService` and `PrincipalService` are
 * passed explicitly to every helper so the using class doesn't have
 * to expose them as properties (which would tie the trait to a
 * specific constructor signature).
 */
trait GroupAuthorizationTrait
{
    use JsonControllerHelpers;

    private const MSG_GROUP_NOT_FOUND = 'Group not found.';

    /**
     * `true` when the caller can edit the group's settings pages:
     * global admin OR `role ∈ {owner, admin}` for the group. The
     * lookup uses the same role-tier strings
     * (`GroupMembership::ROLE_OWNER` / `ROLE_ADMIN`) that
     * {@see \Spora\Services\GroupService} writes.
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
     * `true` when the caller can see the group at all: a row in
     * `group_memberships` for this group. Global admin does NOT bypass
     * here — admins without membership must use the admin-panel overlay
     * for member management, not the group detail page. Used to make
     * the read paths existence-hiding (404 instead of 403) for
     * non-members.
     */
    protected function callerCanSeeGroup(int $groupId, int $userId): bool
    {
        return Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Auth → 401 short-circuit, then group visibility → 404 short-circuit
     * (so non-members can't probe ids), then return `[principalId, userId]`
     * for the caller. Controllers destructure the tuple and proceed.
     *
     * Visibility is collapsed into the principal lookup so the caller
     * only has to check `instanceof JsonResponse` once.
     *
     * @return array{0: int, 1: int}|JsonResponse
     */
    protected function resolveReadableGroup(
        int $id,
        AuthService $authService,
        PrincipalService $principalService,
    ): array|JsonResponse {
        $userId = $this->requireCurrentUserIdOrFail($authService);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $principal = $this->loadGroupPrincipalIfVisible($id, $userId, $principalService);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        return [(int) $principal->id, $userId];
    }

    /**
     * Same as {@see resolveReadableGroup()} but additionally enforces
     * the manage role for write actions. The forbidden message is
     * caller-supplied so each action can name the resource it gates.
     *
     * @return array{0: int, 1: int}|JsonResponse
     */
    protected function resolveWritableGroup(
        int $id,
        string $denyMessage,
        AuthService $authService,
        PrincipalService $principalService,
    ): array|JsonResponse {
        $resolved = $this->resolveReadableGroup($id, $authService, $principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        if (!$this->callerCanManageGroup($id, $userId, $authService)) {
            return $this->forbidden('FORBIDDEN', $denyMessage);
        }

        return [$principalId, $userId];
    }

    /**
     * @return int|JsonResponse
     */
    protected function requireCurrentUserIdOrFail(AuthService $authService): int|JsonResponse
    {
        $userId = $authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }
        return (int) $userId;
    }

    /**
     * Group existence + caller visibility + group-principal existence
     * in one pass — all three failures collapse to the same 404
     * (existence-hiding) and the principal itself is the success path,
     * which keeps the caller at one `instanceof` short-circuit instead
     * of three.
     */
    protected function loadGroupPrincipalIfVisible(
        int $id,
        int $userId,
        PrincipalService $principalService,
    ): Principal|JsonResponse {
        $group = Group::find($id);
        if ($group === null || !$this->callerCanSeeGroup($id, $userId)) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $principal = $principalService->principalForGroup($id);
        if ($principal === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return $principal;
    }
}
