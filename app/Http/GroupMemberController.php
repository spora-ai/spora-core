<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeInterface;
use Spora\Auth\AuthService;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Spora\Models\User;
use Spora\Services\Exceptions\GroupMembershipRuleException;
use Spora\Services\GroupService;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST endpoints for {@see GroupMembership} CRUD.
 *
 *   GET    /api/v1/groups/{id}/members        — list members
 *   POST   /api/v1/groups/{id}/members        — add a member
 *   PATCH  /api/v1/groups/{id}/members/{uid}  — change a member's role
 *   DELETE /api/v1/groups/{id}/members/{uid}  — remove a member
 *
 * Authorisation: global admin OR owner of the group (per
 * {@see GroupService::fetchCallerRole()}). The service enforces the
 * finer-grained role-tier rules (admin can promote/demote to
 * `member`/`admin` but cannot touch `owner` rows).
 */
final class GroupMemberController
{
    use JsonControllerHelpers;

    private const MSG_GROUP_NOT_FOUND = 'Group not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly GroupService $groupService,
        private readonly UserServiceInterface $userService,
    ) {}

    /**
     * GET /api/v1/groups/{id}/members
     */
    public function index(int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        if (!$this->callerCanReadGroup($id, (int) $userId)) {
            // 404 whether the group exists or not — the response is
            // intentionally non-distinguishing so a stranger can't
            // probe group ids.
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['members' => $this->memberRows($id)]]);
    }

    /**
     * POST /api/v1/groups/{id}/members
     */
    public function store(int $id, Request $request): JsonResponse
    {
        $auth = $this->requireCallerAndWriteAccess($id);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        return $this->addMemberAfterAuth($id, $request, $auth[0]);
    }

    /**
     * Add a member after the caller has been authorised by
     * {@see requireCallerAndWriteAccess()}. The split keeps the public
     * `store()` method under the S1142 3-return ceiling.
     */
    private function addMemberAfterAuth(int $groupId, Request $request, int $callerUserId): JsonResponse
    {
        $parsed = $this->parseAddMemberRequest($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        [$targetUserId, $role] = $parsed;

        $error = $this->attemptAddMember($groupId, $targetUserId, $role, $callerUserId);
        if ($error !== null) {
            return $error;
        }

        // Enrich with name + email so the operator sees the new row
        // populated on the next render instead of falling back to
        // `User #${user_id}` until the index re-runs.
        return new JsonResponse(
            ['data' => ['member' => $this->memberRow($targetUserId, $role)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @return array{0: int, 1: string}|JsonResponse
     */
    private function parseAddMemberRequest(Request $request): array|JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->validateAddMemberBody($body);
    }

    private function attemptAddMember(int $groupId, int $targetUserId, string $role, int $callerUserId): ?JsonResponse
    {
        try {
            $this->groupService->addMember($groupId, $targetUserId, $role, $callerUserId);
        } catch (GroupMembershipRuleException $e) {
            return $this->forbidden('FORBIDDEN', $e->getMessage());
        }
        return null;
    }

    /**
     * PATCH /api/v1/groups/{id}/members/{uid}
     */
    public function update(int $id, int $userId, Request $request): JsonResponse
    {
        $auth = $this->requireCallerAndWriteAccess($id);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        return $this->changeMemberRoleAfterAuth($id, $userId, $request, $auth[0]);
    }

    /**
     * Change a member's role after the caller has been authorised by
     * {@see requireCallerAndWriteAccess()}. The split keeps the public
     * `update()` method under the S1142 3-return ceiling.
     */
    private function changeMemberRoleAfterAuth(int $groupId, int $userId, Request $request, int $callerUserId): JsonResponse
    {
        $newRole = $this->parseNewRole($request);
        if ($newRole instanceof JsonResponse) {
            return $newRole;
        }

        $error = $this->attemptRoleChange($groupId, $userId, $newRole, $callerUserId);
        if ($error !== null) {
            return $error;
        }

        // Same enrichment as POST — keeps the wire shape consistent
        // across GET / POST / PATCH so the frontend doesn't need
        // conditional fallback paths.
        return new JsonResponse(['data' => ['member' => $this->memberRow($userId, $newRole)]]);
    }

    private function parseNewRole(Request $request): string|JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->validateRole($body);
    }

    private function attemptRoleChange(int $groupId, int $userId, string $newRole, int $callerUserId): ?JsonResponse
    {
        try {
            $this->groupService->changeMemberRole($groupId, $userId, $newRole, $callerUserId);
        } catch (GroupMembershipRuleException $e) {
            // role-rule violations surface as 409 — the operator must
            // re-shape the request (add another owner before demoting
            // the last one, etc.).
            return new JsonResponse(
                ['error' => ['code' => 'ROLE_RULE_VIOLATION', 'message' => $e->getMessage()]],
                Response::HTTP_CONFLICT,
            );
        }
        return null;
    }

    /**
     * DELETE /api/v1/groups/{id}/members/{uid}
     */
    public function destroy(int $id, int $userId): JsonResponse
    {
        $auth = $this->requireCallerAndWriteAccess($id);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$callerUserId] = $auth;

        try {
            $this->groupService->removeMember($id, $userId, $callerUserId);
        } catch (GroupMembershipRuleException $e) {
            return new JsonResponse(
                ['error' => ['code' => 'ROLE_RULE_VIOLATION', 'message' => $e->getMessage()]],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memberRows(int $groupId): array
    {
        return array_map(
            static function (GroupMembership $m): array {
                /** @var User|null $user Eloquent's `user` relation returns the related model when loaded, null otherwise. */
                $user = $m->user;
                return [
                    'user_id'   => (int) $m->user_id,
                    'role'      => (string) $m->role,
                    'joined_at' => $m->joined_at?->format(DateTimeInterface::ATOM),
                    // Eager-loaded via `with('user:id,name,email')` below.
                    'name'      => $user !== null ? $user->name : null,
                    'email'     => $user !== null ? (string) $user->email : '',
                ];
            },
            GroupMembership::where('group_id', $groupId)
                ->with('user:id,name,email')
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(int $userId, string $role): array
    {
        $user = User::find($userId);
        return [
            'user_id' => $userId,
            'role'    => $role,
            'name'    => $user?->name,
            'email'   => $user !== null ? (string) $user->email : '',
        ];
    }

    private function callerCanReadGroup(int $groupId, int $userId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }
        $role = GroupService::fetchCallerRole($groupId, $userId);
        return $role === GroupMembership::ROLE_OWNER || $role === GroupMembership::ROLE_ADMIN;
    }

    /**
     * Common preamble for store/update/destroy: returns [callerUserId]
     * when both checks pass; or a JsonResponse to short-circuit.
     *
     * @return array{0: int}|JsonResponse
     */
    private function requireCallerAndWriteAccess(int $groupId): array|JsonResponse
    {
        $callerUserId = $this->authService->currentUserId();
        if ($callerUserId === null) {
            return $this->unauthenticated();
        }

        $authError = $this->authoriseMemberWrite($groupId, $callerUserId);
        if ($authError !== null) {
            return $authError;
        }

        return [$callerUserId];
    }

    /**
     * @param  array<string, mixed> $body
     * @return array{0: int, 1: string}|JsonResponse
     */
    private function validateAddMemberBody(array $body): array|JsonResponse
    {
        $hasUserId = isset($body['user_id']) && (int) $body['user_id'] > 0;
        $hasEmail = isset($body['email']) && trim((string) $body['email']) !== '';

        // Exactly one of `user_id` or `email` is required — the wire
        // contract accepts either so the frontend can pick the friendlier
        // input (email) while machine-to-machine callers keep their
        // integer-id path.
        if ($hasUserId === $hasEmail) {
            return $this->unprocessable(
                'VALIDATION_ERROR',
                'Provide exactly one of "user_id" (integer) or "email" (string).',
            );
        }

        if ($hasUserId) {
            $targetUserId = (int) $body['user_id'];
        } else {
            $email = strtolower(trim((string) $body['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->unprocessable('VALIDATION_ERROR', 'The "email" field must be a valid email address.');
            }
            $targetUserId = $this->userService->getUserIdByEmail($email);
            if ($targetUserId === null) {
                return $this->notFound(
                    'USER_NOT_FOUND',
                    sprintf('No user exists with email "%s".', $email),
                );
            }
        }

        $role = (string) ($body['role'] ?? GroupMembership::ROLE_MEMBER);
        $roleError = $this->validateRole(['role' => $role]);
        if ($roleError instanceof JsonResponse) {
            return $roleError;
        }

        return [$targetUserId, $roleError];
    }

    /**
     * @param  array<string, mixed> $body
     * @return string|JsonResponse (the validated role, or an error envelope)
     */
    private function validateRole(array $body): string|JsonResponse
    {
        $role = (string) ($body['role'] ?? '');
        if (!in_array($role, [GroupMembership::ROLE_OWNER, GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_MEMBER], true)) {
            return $this->unprocessable('VALIDATION_ERROR', "Unknown role: {$role}");
        }
        return $role;
    }

    /**
     * Authorisation: caller must be admin OR owner of the group.
     * Returns the 403/404 envelope on failure, or null on success.
     */
    private function authoriseMemberWrite(int $groupId, int $callerUserId): ?JsonResponse
    {
        $groupOrError = $this->loadGroupOrNotFound($groupId);
        if ($groupOrError instanceof JsonResponse) {
            return $groupOrError;
        }

        if ($this->callerMayManageMembers($groupId, $callerUserId)) {
            return null;
        }

        return $this->forbidden('FORBIDDEN', 'Only group owners or admins can manage members.');
    }

    private function callerMayManageMembers(int $groupId, int $callerUserId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }
        return GroupService::fetchCallerRole($groupId, $callerUserId) === GroupMembership::ROLE_OWNER;
    }

    private function loadGroupOrNotFound(int $groupId): Group|JsonResponse
    {
        $group = Group::find($groupId);
        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }
        return $group;
    }
}
