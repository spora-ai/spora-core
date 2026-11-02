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

    public function __construct(
        private readonly ApiKeyGroupService $groups,
    ) {}

    #[OA\Get(summary: 'The group this key belongs to', tags: ['Group-api'])]
    #[OA\Response(response: 200, description: 'The key\'s group.')]
    #[OA\Response(response: 404, description: 'No such group.')]
    public function ownGroup(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $group = $context->groupId === null ? null : Group::find($context->groupId);

        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', 'Group not found.');
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

        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'name is required.');
        }

        $ownerUserId = $context->key->created_by;

        if ($ownerUserId === null) {
            // A group with no owner cannot be administered by anybody, and
            // the last-owner guard would have nothing to protect.
            return $this->forbidden('FORBIDDEN', 'This key has no owner to create a group with.');
        }

        $description = isset($body['description']) ? trim((string) $body['description']) : null;
        $group = $this->groups->create((int) $ownerUserId, $name, $description === '' ? null : $description);

        return new JsonResponse(
            ['data' => ['group' => $this->groups->present($group, (int) $ownerUserId)]],
            Response::HTTP_CREATED,
        );
    }

    #[OA\Get(summary: 'Read a group', tags: ['Global-api'])]
    #[OA\Response(response: 200, description: 'The group.')]
    #[OA\Response(response: 404, description: 'No such group.')]
    public function show(Request $request, int $groupId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $group = Group::find($groupId);

        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', 'Group not found.');
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
    #[OA\Response(response: 404, description: 'No such group.')]
    #[OA\Response(response: 422, description: 'The name was empty.')]
    public function update(Request $request, int $groupId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        if (Group::find($groupId) === null) {
            return $this->notFound('GROUP_NOT_FOUND', 'Group not found.');
        }

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

        $group = $this->groups->update($groupId, $changes);

        return new JsonResponse([
            'data' => ['group' => $this->groups->present($group, $this->callerUserId($context))],
        ]);
    }

    #[OA\Delete(summary: 'Delete a group', tags: ['Global-api'])]
    #[OA\Response(response: 200, description: 'The group was deleted.')]
    #[OA\Response(response: 404, description: 'No such group.')]
    #[OA\Response(response: 409, description: 'The group still owns agents.')]
    public function destroy(Request $request, int $groupId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);

        if (Group::find($groupId) === null) {
            return $this->notFound('GROUP_NOT_FOUND', 'Group not found.');
        }

        try {
            $this->groups->delete($groupId, $this->callerUserId($context));
        } catch (GroupMembershipRuleException $e) {
            return $this->forbidden('FORBIDDEN', $e->getMessage());
        } catch (PrincipalHasDependentsException $e) {
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

        return new JsonResponse(['data' => ['deleted' => true, 'group_id' => $groupId]]);
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
