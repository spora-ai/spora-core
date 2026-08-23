<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Models\Agent;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Services\AgentResource;
use Spora\Services\Exceptions\GroupMembershipRuleException;
use Spora\Services\Exceptions\PrincipalHasDependentsException;
use Spora\Services\GroupDetailResource;
use Spora\Services\GroupService;
use Spora\Services\PrincipalService;
use Spora\Services\ProfilePictures\GroupPictureService;
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
 *   GET    /api/v1/groups/{id}/agents     — list agents whose principal_id matches the group's group-principal
 *
 * Authorisation is principally driven by the middleware stack
 * ({@see Middleware\AdminMiddleware} gates the writes), but the
 * destroy path also surfaces a structured 409 with the orphan agent
 * ids so the operator can either transfer them first or delete them.
 *
 * Wire-format mapping moved to {@see GroupDetailResource} so the
 * controller stays under SonarCloud's S1448 20-method-per-class cap
 * (single source of truth shared with {@see GroupDetailResource}).
 */
final class GroupController
{
    use JsonControllerHelpers;
    use GroupAuthorizationTrait;

    private const MSG_GROUP_NOT_FOUND = 'Group not found.';
    private const DB_TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly AuthService $authService,
        private readonly GroupService $groupService,
        private readonly PrincipalService $principalService,
        private readonly GroupPictureService $pictureService = new GroupPictureService(),
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
            ? $this->listAllGroups((int) $userId)
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
        if ($group === null || !$this->callerCanSeeGroup($id, (int) $userId, $this->authService)) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return new JsonResponse([
            'data' => ['group' => GroupDetailResource::toArray($group, (int) $userId, $this->principalService, $this->pictureService)],
        ]);
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

        $created = $this->createGroupFromRequest($request);
        if ($created instanceof JsonResponse) {
            return $created;
        }
        [$name, $description] = $created;

        $group = $this->groupService->createGroup((int) $userId, $name, $description);

        return new JsonResponse(
            ['data' => ['group' => GroupDetailResource::toArray($group, (int) $userId, $this->principalService, $this->pictureService)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * PATCH /api/v1/groups/{id}
     *
     * Admin-only via middleware. The groups-row write and the optional
     * `profile_picture` write share a single transaction so a partial
     * update can't leave the row and its picture out of sync.
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $resolved = $this->resolveGroupUpdate($id, $request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [, $updates, $picturePayload] = $resolved;

        if ($updates !== []) {
            $updates['updated_at'] = date(self::DB_TIMESTAMP_FORMAT);
        }

        Capsule::connection()->transaction(function () use ($id, $updates, $picturePayload): void {
            if ($updates !== []) {
                Capsule::table('groups')->where('id', $id)->update($updates);
            }
            if ($picturePayload !== []) {
                $this->applyProfilePictureUpdate($id, $picturePayload);
            }
        });

        $group = Group::findOrFail($id);
        $userId = $this->authService->currentUserId() ?? 0;
        return new JsonResponse([
            'data' => ['group' => GroupDetailResource::toArray($group, $userId, $this->principalService, $this->pictureService)],
        ]);
    }

    /**
     * Write the optional `profile_picture` nested object to the
     * group's `group_pictures` row. Mirrors
     * {@see AgentController::applyProfilePictureUpdate()}
     * — validation runs *before* the groups-row write (via
     * {@see resolveGroupUpdateChanges()}) so an invalid picture never
     * partially overwrites the name / description.
     *
     * @param array<string, string> $picturePayload
     */
    private function applyProfilePictureUpdate(int $groupId, array $picturePayload): void
    {
        if ($picturePayload === []) {
            return;
        }

        $this->pictureService->updateAvatar(
            $groupId,
            isset($picturePayload['archetype']) ? (string) $picturePayload['archetype'] : null,
            isset($picturePayload['variant_key']) ? (string) $picturePayload['variant_key'] : null,
            isset($picturePayload['palette_key']) ? (string) $picturePayload['palette_key'] : null,
        );
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

    /**
     * GET /api/v1/groups/{id}/agents
     *
     * Lists every agent whose `principal_id` matches the group's
     * group-principal. The principal axis is the bug-prone bit — the
     * caller's user-principal id (`PrincipalResolver::principalForUser`)
     * must NOT be used here, or the response would leak the caller's
     * own agents under the group's url.
     */
    public function agents(int $id): JsonResponse
    {
        $userId = $this->requireUserOrFail();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $principal = $this->loadGroupPrincipalOrFail($id, (int) $userId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        $rows = Agent::where('principal_id', (int) $principal->id)
            ->orderByDesc('created_at')
            ->get();

        return new JsonResponse([
            'data' => [
                'agents' => array_map(
                    static fn(Agent $a): array => AgentResource::toArray($a),
                    $rows->all(),
                ),
                'total' => $rows->count(),
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: ?string}|JsonResponse
     */
    private function createGroupFromRequest(Request $request): array|JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

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
     * @return array{0: Group, 1: array<string, mixed>, 2: array<string, mixed>|null}|JsonResponse
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

        return [$group, $changes[0], $changes[1]];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}|JsonResponse
     */
    private function resolveGroupUpdateChanges(Request $request): array|JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->buildUpdatePayload($body);
    }

    private function attemptDelete(int $id, int $userId): ?JsonResponse
    {
        try {
            $this->groupService->deleteGroup($id, $userId, $this->authService->isAdmin());
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
     * @param  array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: array<string, string>}|JsonResponse
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

        $picturePayload = $this->validateProfilePicturePayload($body);
        if ($picturePayload instanceof JsonResponse) {
            return $picturePayload;
        }
        return [$update, $picturePayload];
    }

    /**
     * Validate the optional `profile_picture` nested payload. Returns
     * the validated payload (empty array when the key is absent) or a
     * 422 JsonResponse on the first failure. Delegates to
     * {@see \Spora\Services\ProfilePictures\ProfilePictureService::validatePayload()}
     * so the wire contract is shared with {@see AgentController}.
     *
     * @param  array<string, mixed> $body
     * @return array<string, string>|JsonResponse
     */
    private function validateProfilePicturePayload(array $body): array|JsonResponse
    {
        if (!array_key_exists('profile_picture', $body)) {
            return [];
        }
        $validated = $this->pictureService->validatePayload($body['profile_picture']);
        if ($validated instanceof \Spora\Services\ProfilePictures\ProfilePictureValidationError) {
            return $this->unprocessable($validated->code, $validated->message);
        }
        /** @var array<string, string> $validated */
        return $validated;
    }

    /**
     * Load the group + verify the caller may see it + resolve the
     * group's principal. The two 404-not-visible states (no group /
     * not a member) collapse to the same notFound response, which lets
     * {@see agents()} stay at the S1142 3-return cap.
     */
    private function loadGroupPrincipalOrFail(int $id, int $userId): Principal|JsonResponse
    {
        $group = Group::find($id);
        if ($group === null || !$this->callerCanSeeGroup($id, $userId, $this->authService)) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $principal = $this->principalService->principalForGroup($id);
        if ($principal === null) {
            return new JsonResponse(['data' => ['agents' => [], 'total' => 0]]);
        }

        return $principal;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAllGroups(int $userId): array
    {
        $rows = Group::orderBy('name')->get();
        return GroupDetailResource::collect($rows->all(), $userId, $this->principalService, $this->pictureService);
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
        return GroupDetailResource::collect($rows->all(), $userId, $this->principalService, $this->pictureService);
    }
}
