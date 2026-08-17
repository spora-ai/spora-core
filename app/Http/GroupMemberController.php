<?php

declare(strict_types=1);

namespace Spora\Http;

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
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $group = $this->loadGroupOrNotFound($groupId);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        $role = GroupService::fetchCallerRole($groupId, $userId);
        if (!$this->authService->isAdmin() && $role === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $rows = GroupMembership::where('group_id', $groupId)
            ->orderBy('id')
            ->get();

        $members = array_map(
            static fn(GroupMembership $m): array => [
                'user_id'   => (int) $m->user_id,
                'role'      => (string) $m->role,
                'joined_at' => $m->joined_at?->format(\DateTimeInterface::ATOM),
            ],
            $rows->all(),
        );

        return new JsonResponse(['data' => ['members' => $members]]);
    }

    /**
     * POST /api/v1/groups/{id}/members
     */
    public function store(int $groupId, Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $authError = $this->authoriseMemberWrite($groupId, $userId);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }

        $targetUserId = (int) ($body['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            return $this->unprocessable('VALIDATION_ERROR', 'user_id is required.');
        }
        $role = (string) ($body['role'] ?? GroupMembership::ROLE_MEMBER);
        if (!in_array($role, [GroupMembership::ROLE_OWNER, GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_MEMBER], true)) {
            return $this->unprocessable('VALIDATION_ERROR', "Unknown role: {$role}");
        }

        try {
            $this->groupService->addMember($groupId, $targetUserId, $role, $userId);
        } catch (GroupMembershipRuleException $e) {
            return $this->forbidden('FORBIDDEN', $e->getMessage());
        }

        return new JsonResponse(
            ['data' => [
                'member' => [
                    'user_id' => $targetUserId,
                    'role'    => $role,
                ],
            ]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * PATCH /api/v1/groups/{id}/members/{uid}
     */
    public function update(int $groupId, int $userId, Request $request): JsonResponse
    {
        $callerUserId = $this->authService->currentUserId();
        if ($callerUserId === null) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $authError = $this->authoriseMemberWrite($groupId, $callerUserId);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }

        $newRole = (string) ($body['role'] ?? '');
        if (!in_array($newRole, [GroupMembership::ROLE_OWNER, GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_MEMBER], true)) {
            return $this->unprocessable('VALIDATION_ERROR', "Unknown role: {$newRole}");
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

        return new JsonResponse(['data' => [
            'member' => [
                'user_id' => $userId,
                'role'    => $newRole,
            ],
        ]]);
    }

    /**
     * DELETE /api/v1/groups/{id}/members/{uid}
     */
    public function destroy(int $groupId, int $userId): JsonResponse
    {
        $callerUserId = $this->authService->currentUserId();
        if ($callerUserId === null) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $authError = $this->authoriseMemberWrite($groupId, $callerUserId);
        if ($authError !== null) {
            return $authError;
        }

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
     * Authorisation: caller must be admin OR owner of the group.
     * Returns the 403/404 envelope on failure, or null on success.
     */
    private function authoriseMemberWrite(int $groupId, int $callerUserId): ?JsonResponse
    {
        $group = $this->loadGroupOrNotFound($groupId);
        if ($group instanceof JsonResponse) {
            return $group;
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
