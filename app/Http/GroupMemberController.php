<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeInterface;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Spora\Services\Exceptions\GroupMembershipRuleException;
use Spora\Services\GroupService;
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

    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';
    private const MSG_GROUP_NOT_FOUND = 'Group not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly GroupService $groupService,
    ) {}

    /**
     * GET /api/v1/groups/{id}/members
     */
    public function index(int $groupId): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $groupOrError = $this->loadGroupOrNotFound($groupId);
        if ($groupOrError instanceof JsonResponse) {
            return $groupOrError;
        }

        if (!$this->callerCanReadGroup($groupId, $userId)) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['members' => $this->memberRows($groupId)]]);
    }

    /**
     * POST /api/v1/groups/{id}/members
     */
    public function store(int $groupId, Request $request): JsonResponse
    {
        $auth = $this->requireCallerAndWriteAccess($groupId);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$callerUserId] = $auth;

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validated = $this->validateAddMemberBody($body);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        [$targetUserId, $role] = $validated;

        try {
            $this->groupService->addMember($groupId, $targetUserId, $role, $callerUserId);
        } catch (GroupMembershipRuleException $e) {
            return $this->forbidden('FORBIDDEN', $e->getMessage());
        }

        return new JsonResponse(
            ['data' => ['member' => ['user_id' => $targetUserId, 'role' => $role]]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * PATCH /api/v1/groups/{id}/members/{uid}
     */
    public function update(int $groupId, int $userId, Request $request): JsonResponse
    {
        $auth = $this->requireCallerAndWriteAccess($groupId);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$callerUserId] = $auth;

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $newRole = $this->validateRole($body);
        if ($newRole instanceof JsonResponse) {
            return $newRole;
        }

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

        return new JsonResponse(['data' => ['member' => ['user_id' => $userId, 'role' => $newRole]]]);
    }

    /**
     * DELETE /api/v1/groups/{id}/members/{uid}
     */
    public function destroy(int $groupId, int $userId): JsonResponse
    {
        $auth = $this->requireCallerAndWriteAccess($groupId);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$callerUserId] = $auth;

        try {
            $this->groupService->removeMember($groupId, $userId, $callerUserId);
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
            static fn(GroupMembership $m): array => [
                'user_id'   => (int) $m->user_id,
                'role'      => (string) $m->role,
                'joined_at' => $m->joined_at?->format(DateTimeInterface::ATOM),
            ],
            GroupMembership::where('group_id', $groupId)->orderBy('id')->get()->all(),
        );
    }

    private function callerCanReadGroup(int $groupId, int $userId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }
        return GroupService::fetchCallerRole($groupId, $userId) !== null;
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
     * @return array<string, mixed>|JsonResponse
     */
    private function safeDecodeJson(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @param  array<string, mixed> $body
     * @return array{0: int, 1: string}|JsonResponse
     */
    private function validateAddMemberBody(array $body): array|JsonResponse
    {
        $targetUserId = (int) ($body['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            return $this->unprocessable('VALIDATION_ERROR', 'user_id is required.');
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

        if ($this->authService->isAdmin()) {
            return null;
        }

        $role = GroupService::fetchCallerRole($groupId, $callerUserId);
        if ($role !== GroupMembership::ROLE_OWNER) {
            return $this->forbidden('FORBIDDEN', 'Only group owners or admins can manage members.');
        }

        return null;
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
