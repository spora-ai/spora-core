<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeInterface;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Spora\Services\Exceptions\GroupMembershipRuleException;
use Spora\Services\Exceptions\PrincipalHasDependentsException;
use Spora\Services\GroupService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST endpoints for {@see Group} CRUD.
 *
 *   GET    /api/v1/groups                 — members see the groups they belong to; admins see all
 *   GET    /api/v1/groups/{id}            — show one group
 *   POST   /api/v1/groups                 — create (admin-only); creator becomes owner + group-principal is materialised
 *   PATCH  /api/v1/groups/{id}            — update (admin-only)
 *   DELETE /api/v1/groups/{id}            — destroy (admin-only); 409 if agents still reference the group-principal
 *
 * Authorisation is principally driven by the middleware stack
 * ({@see Middleware\AdminMiddleware} gates the writes), but the
 * destroy path also surfaces a structured 409 with the orphan agent
 * ids so the operator can either transfer them first or delete them.
 */
final class GroupController
{
    use JsonControllerHelpers;

    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';
    private const MSG_GROUP_NOT_FOUND = 'Group not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly GroupService $groupService,
        private readonly PrincipalService $principalService,
    ) {}

    /**
     * GET /api/v1/groups
     *
     * Members see the groups they belong to; admins see every group.
     */
    public function index(): JsonResponse
    {
        $userId = $this->requireUserOrFail();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $groups = $this->authService->isAdmin()
            ? $this->listAllGroups()
            : $this->listGroupsForMember((int) $userId);

        return new JsonResponse(['data' => ['groups' => $groups]]);
    }

    /**
     * GET /api/v1/groups/{id}
     */
    public function show(int $id): JsonResponse
    {
        $userId = $this->requireUserOrFail();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $group = Group::find($id);
        if ($group === null || !$this->callerCanSeeGroup($id, (int) $userId)) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['group' => $this->groupResource($group, (int) $userId)]]);
    }

    /**
     * POST /api/v1/groups
     *
     * Admin-only via middleware. The service inserts the creator as
     * `role: owner` and materialises the group-principal in the same
     * transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->requireUserOrFail();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $created = $this->createGroupFromRequest($request, (int) $userId);
        if ($created instanceof JsonResponse) {
            return $created;
        }
        [$name, $description] = $created;

        $group = $this->groupService->createGroup((int) $userId, $name, $description);

        return new JsonResponse(
            ['data' => ['group' => $this->groupResource($group, (int) $userId)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @return array{0: string, 1: ?string}|JsonResponse
     */
    private function createGroupFromRequest(Request $request, int $userId): array|JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->validateCreateBody($body);
    }

    /**
     * PATCH /api/v1/groups/{id}
     *
     * Admin-only via middleware.
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $resolved = $this->resolveGroupUpdate($id, $request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$group, $updates] = $resolved;

        if ($updates !== []) {
            $group = $this->applyGroupRowUpdate($id, $group, $updates);
        }

        $userId = $this->authService->currentUserId() ?? 0;
        return new JsonResponse(['data' => ['group' => $this->groupResource($group, $userId)]]);
    }

    /**
     * @return array{0: Group, 1: array<string, mixed>}|JsonResponse
     */
    private function resolveGroupUpdate(int $id, Request $request): array|JsonResponse
    {
        $group = Group::find($id);
        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $changes = $this->resolveGroupUpdateChanges($request);
        if ($changes instanceof JsonResponse) {
            return $changes;
        }

        return [$group, $changes];
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function resolveGroupUpdateChanges(Request $request): array|JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->buildUpdatePayload($body);
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function applyGroupRowUpdate(int $id, Group $group, array $updates): Group
    {
        $updates['updated_at'] = date('Y-m-d H:i:s');
        \Illuminate\Database\Capsule\Manager::table('groups')
            ->where('id', $id)
            ->update($updates);
        return Group::findOrFail($id);
    }

    /**
     * DELETE /api/v1/groups/{id}
     *
     * Admin-only via middleware. The GroupService pre-flight refuses if
     * any agent still references the group-principal; we surface a 409
     * with the orphan ids so the operator can transfer or delete them.
     */
    public function destroy(int $id): JsonResponse
    {
        $userId = $this->requireUserOrFail();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $error = $this->attemptDelete($id, (int) $userId);
        if ($error !== null) {
            return $error;
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    private function attemptDelete(int $id, int $userId): ?JsonResponse
    {
        try {
            $this->groupService->deleteGroup($id, $userId);
        } catch (GroupMembershipRuleException $e) {
            return $this->forbidden('FORBIDDEN', $e->getMessage());
        } catch (PrincipalHasDependentsException $e) {
            return $this->conflictWithDependents($e->getMessage(), $e->agentIds);
        }
        return null;
    }

    /**
     * Build the 409 response when the group still owns agents. The
     * `agent_ids` field lets the operator UI link to the transfer
     * endpoint so they can re-target each agent before retrying.
     *
     * @param  list<int> $agentIds
     */
    private function conflictWithDependents(string $message, array $agentIds): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => [
                    'code'    => 'GROUP_HAS_AGENTS',
                    'message' => $message,
                    'agent_ids' => $agentIds,
                    'reassign_endpoint' => \Spora\Core\RouteDefinitions::ROUTE_AGENTS_TRANSFER,
                ],
            ],
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * Returns the authenticated user id (as int) or a 401 JsonResponse
     * if no user is logged in. The nullable return is intentional: we
     * want callers to be able to short-circuit on failure in one line.
     *
     * @return int|JsonResponse
     */
    private function requireUserOrFail(): int|JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }
        return (int) $userId;
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
     * @return array{0: string, 1: ?string}|JsonResponse
     */
    private function validateCreateBody(array $body): array|JsonResponse
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'name is required.');
        }
        $description = isset($body['description']) ? trim((string) $body['description']) : null;
        if ($description === '') {
            $description = null;
        }
        return [$name, $description];
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>|JsonResponse
     */
    private function buildUpdatePayload(array $body): array|JsonResponse
    {
        $update = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return $this->unprocessable('VALIDATION_ERROR', 'name cannot be empty.');
            }
            $update['name'] = $name;
        }
        if (array_key_exists('description', $body)) {
            $description = $body['description'] === null ? null : trim((string) $body['description']);
            $update['description'] = ($description === '') ? null : $description;
        }
        return $update;
    }

    private function callerCanSeeGroup(int $groupId, int $userId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }
        return GroupMembership::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Wire-format group row. The `principal_id` is included so the
     * dashboard can hand it back to the agent transfer endpoint;
     * `role` is the caller's role in this group (or null when not a
     * member).
     *
     * @return array<string, mixed>
     */
    private function groupResource(Group $group, int $callerUserId): array
    {
        $principal = $this->principalService->principalForGroup((int) $group->id);
        $role = GroupMembership::where('group_id', $group->id)
            ->where('user_id', $callerUserId)
            ->value('role');

        return [
            'id'                  => (int) $group->id,
            'name'                => $group->name,
            'description'         => $group->description,
            'created_by_user_id'  => (int) $group->created_by_user_id,
            'principal_id'        => $principal !== null ? (int) $principal->id : null,
            'caller_role'         => $role !== null ? (string) $role : null,
            'created_at'          => $group->created_at->format(DateTimeInterface::ATOM),
            'updated_at'          => $group->updated_at->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAllGroups(): array
    {
        $rows = Group::orderBy('name')->get();
        $userId = $this->authService->currentUserId() ?? 0;
        return array_map(
            fn(Group $g): array => $this->groupResource($g, $userId),
            $rows->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listGroupsForMember(int $userId): array
    {
        $groupIds = GroupMembership::where('user_id', $userId)->pluck('group_id');
        if ($groupIds->isEmpty()) {
            return [];
        }
        $rows = Group::whereIn('id', $groupIds)->orderBy('name')->get();
        return array_map(
            fn(Group $g): array => $this->groupResource($g, $userId),
            $rows->all(),
        );
    }
}
