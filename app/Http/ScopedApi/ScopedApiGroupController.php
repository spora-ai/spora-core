<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use OpenApi\Attributes as OA;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\Group;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\ApiKeys\ApiKeyGroupService;
use Spora\Services\Exceptions\GroupMembershipRuleException;
use Spora\Services\Exceptions\PrincipalHasDependentsException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Group CRUD for the global API surface, and the group surface's own group.
 *
 * A group key reads one group — its own — and cannot list or mutate groups.
 * The global key does all of it.
 */
final class ScopedApiGroupController
{
    use JsonControllerHelpers;

    private const MSG_NO_SUCH_GROUP = 'No such group.';

    private const MSG_GROUP_NOT_FOUND = 'Group not found.';

    private const MSG_NO_OWNER = 'This key has no owner to create a group with.';

    public function __construct(
        private readonly ApiKeyGroupService $groups,
    ) {}

    #[OA\Get(summary: 'The group this key belongs to', tags: ['Group-api'])]
    #[OA\Response(response: 200, description: 'The key\'s group.')]
    #[OA\Response(response: 404, description: self::MSG_NO_SUCH_GROUP)]
    public function ownGroup(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $group = $context->groupId === null ? null : Group::find($context->groupId);

        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_NO_SUCH_GROUP);
        }

        return new JsonResponse([
            'data' => ['group' => $this->groups->present($group, $this->callerUserId($context))],
        ]);
    }

    #[OA\Get(summary: 'List every group', tags: ['Global-api'])]
    #[OA\Response(response: 200, description: 'Every group in the install.')]
    public function index(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $callerUserId = $this->callerUserId($context);

        return new JsonResponse([
            'data' => [
                'groups' => array_map(
                    fn(Group $group): array => $this->groups->present($group, $callerUserId),
                    $this->groups->listGroups(),
                ),
            ],
        ]);
    }

    #[OA\Post(summary: 'Create a group', tags: ['Global-api'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name'],
        properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'description', type: 'string'),
        ],
    ))]
    #[OA\Response(response: 201, description: 'The group was created.')]
    #[OA\Response(response: 403, description: 'The key has no owner to create a group with.')]
    #[OA\Response(response: 422, description: 'The name was missing.')]
    public function store(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $created = $this->createGroup($context, $body);

        if ($created instanceof JsonResponse) {
            return $created;
        }

        return new JsonResponse(
            ['data' => ['group' => $this->groups->present($created, $this->callerUserId($context))]],
            Response::HTTP_CREATED,
        );
    }

    #[OA\Get(summary: 'Read a group', tags: ['Global-api'])]
    #[OA\Response(response: 200, description: 'The group.')]
    #[OA\Response(response: 404, description: self::MSG_GROUP_NOT_FOUND)]
    public function show(Request $request, int $groupId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $group = Group::find($groupId);

        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return new JsonResponse([
            'data' => ['group' => $this->groups->present($group, $this->callerUserId($context))],
        ]);
    }

    #[OA\Patch(summary: 'Update a group', tags: ['Global-api'])]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'description', type: 'string'),
        ],
    ))]
    #[OA\Response(response: 200, description: 'The group was updated.')]
    #[OA\Response(response: 404, description: self::MSG_GROUP_NOT_FOUND)]
    #[OA\Response(response: 422, description: 'The name was empty.')]
    public function update(Request $request, int $groupId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $updated = $this->applyUpdate($groupId, $body);

        if ($updated instanceof JsonResponse) {
            return $updated;
        }

        return new JsonResponse([
            'data' => ['group' => $this->groups->present($updated, $this->callerUserId($context))],
        ]);
    }

    #[OA\Delete(summary: 'Delete a group', tags: ['Global-api'])]
    #[OA\Response(response: 200, description: 'The group was deleted.')]
    #[OA\Response(response: 404, description: self::MSG_GROUP_NOT_FOUND)]
    #[OA\Response(response: 409, description: 'The group still owns agents.')]
    public function destroy(Request $request, int $groupId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);

        if (Group::find($groupId) === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $failure = $this->deleteGroup($groupId, $context);

        if ($failure !== null) {
            return $failure;
        }

        return new JsonResponse(['data' => ['deleted' => true, 'group_id' => $groupId]]);
    }

    /**
     * Create the group, or say why it cannot be created.
     *
     * @param array<string, mixed> $body
     * @return JsonResponse|Group
     */
    private function createGroup(ApiKeyContext $context, array $body): JsonResponse|Group
    {
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'name is required.');
        }

        $ownerUserId = $context->key->created_by;

        if ($ownerUserId === null) {
            // A group with no owner cannot be administered by anybody, and
            // the last-owner guard would have nothing to protect.
            return $this->forbidden('FORBIDDEN', self::MSG_NO_OWNER);
        }

        $description = isset($body['description']) ? trim((string) $body['description']) : null;

        return $this->groups->create((int) $ownerUserId, $name, $description === '' ? null : $description);
    }

    /**
     * Apply the changes to the group, or say why they cannot be applied.
     *
     * @param array<string, mixed> $body
     * @return JsonResponse|Group
     */
    private function applyUpdate(int $groupId, array $body): JsonResponse|Group
    {
        if (Group::find($groupId) === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $changes = $this->groupChanges($body);

        if ($changes instanceof JsonResponse) {
            return $changes;
        }

        return $this->groups->update($groupId, $changes);
    }

    /**
     * The fields the payload changes.
     *
     * A field the payload does not name is left alone — "not mentioned" and
     * "set to nothing" are different edits.
     *
     * @param array<string, mixed> $body
     * @return array{name?: string, description?: ?string}|JsonResponse
     */
    private function groupChanges(array $body): array|JsonResponse
    {
        $changes = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);

            if ($name === '') {
                return $this->unprocessable('VALIDATION_ERROR', 'name cannot be empty.');
            }

            $changes['name'] = $name;
        }

        if (array_key_exists('description', $body)) {
            $description = $body['description'] === null ? null : trim((string) $body['description']);
            $changes['description'] = $description === '' ? null : $description;
        }

        return $changes;
    }

    /**
     * Delete the group, or say why it cannot be deleted.
     */
    private function deleteGroup(int $groupId, ApiKeyContext $context): ?JsonResponse
    {
        try {
            $this->groups->delete($groupId, $this->callerUserId($context));
        } catch (GroupMembershipRuleException $e) {
            return $this->forbidden('FORBIDDEN', $e->getMessage());
        } catch (PrincipalHasDependentsException $e) {
            return $this->conflictWithDependents($e);
        }

        return null;
    }

    /**
     * The 409 for a group that still owns something.
     *
     * A folder is not an agent: the transfer endpoint cannot re-target
     * one, so the answer names the folder route and the agent payload
     * is absent.
     */
    private function conflictWithDependents(PrincipalHasDependentsException $e): JsonResponse
    {
        if ($e->folderIds !== []) {
            return new JsonResponse(
                [
                    'error' => [
                        'code' => 'GROUP_HAS_MEDIA_FOLDERS',
                        'message' => $e->getMessage(),
                        'folder_ids' => $e->folderIds,
                    ],
                ],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(
            [
                'error' => [
                    'code' => 'GROUP_HAS_AGENTS',
                    'message' => $e->getMessage(),
                    'agent_ids' => $e->agentIds,
                ],
            ],
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * The user the group's `my_role` is reported for.
     *
     * The admin who minted the key: the closest thing a global credential
     * has to a person.
     */
    private function callerUserId(ApiKeyContext $context): int
    {
        return $context->key->created_by ?? 0;
    }
}
