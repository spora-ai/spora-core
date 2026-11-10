<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Services\PrincipalLocationService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET / POST / PATCH / DELETE` for the locations saved for a group.
 *
 * The rows live on the group's group-principal, so this is the same
 * surface as `/me/locations` seen from the other end: one location
 * concept, one table, one service.
 *
 * Authorisation: read uses `callerCanSeeGroup()` (members can list);
 * write uses `callerCanManageGroup()` (owner / admin / global admin).
 * A member below `admin` is refused; a non-member is not told the group
 * exists.
 *
 * Endpoints:
 *   GET    /api/v1/groups/{id}/locations
 *   POST   /api/v1/groups/{id}/locations
 *   PATCH  /api/v1/groups/{id}/locations/{locationId}
 *   DELETE /api/v1/groups/{id}/locations/{locationId}
 */
final class GroupLocationController
{
    use JsonControllerHelpers;
    use GroupAuthorizationTrait;

    private const MSG_CANNOT_MANAGE = 'Only group owners or admins can edit locations.';

    private const MSG_LOCATION_NOT_FOUND = 'Location not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly PrincipalService $principalService,
        private readonly PrincipalLocationService $locations,
    ) {}

    #[OA\Get(
        path: '/api/v1/groups/{id}/locations',
        summary: "List a group's saved locations",
        tags: ['Groups'],
    )]
    #[OA\Response(response: 200, description: 'The locations saved for this group.')]
    #[OA\Response(response: 404, description: 'The group does not exist, or the caller is not a member.')]
    public function index(int $id): JsonResponse
    {
        $resolved = $this->resolveReadableGroup($id, $this->authService, $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId] = $resolved;

        return new JsonResponse([
            'data' => ['locations' => $this->locations->listFor($principalId)],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/groups/{id}/locations',
        summary: 'Save a location for a group',
        tags: ['Groups'],
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name', 'address'],
        properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 100),
            new OA\Property(property: 'address', type: 'string'),
            new OA\Property(property: 'is_default', type: 'boolean'),
        ],
    ))]
    #[OA\Response(response: 201, description: 'The saved location.')]
    #[OA\Response(response: 403, description: 'The caller is a member of the group but not an owner or admin.')]
    #[OA\Response(response: 404, description: 'The group does not exist, or the caller is not a member.')]
    #[OA\Response(response: 422, description: 'The name or the address was missing or empty.')]
    public function store(int $id, Request $request): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, self::MSG_CANNOT_MANAGE, $this->authService, $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $invalid = $this->validateFields($body, true);
        if ($invalid !== null) {
            return $invalid;
        }

        $created = $this->locations->create($this->principalIdFor($id), $body);

        return new JsonResponse(['data' => ['location' => $created['location']]], Response::HTTP_CREATED);
    }

    #[OA\Patch(
        path: '/api/v1/groups/{id}/locations/{locationId}',
        summary: "Edit one of a group's saved locations",
        tags: ['Groups'],
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 100),
            new OA\Property(property: 'address', type: 'string'),
            new OA\Property(property: 'is_default', type: 'boolean'),
        ],
    ))]
    #[OA\Response(response: 200, description: 'The updated location.')]
    #[OA\Response(response: 403, description: 'The caller is a member of the group but not an owner or admin.')]
    #[OA\Response(response: 404, description: 'The group or the location does not exist, or the caller is not a member.')]
    #[OA\Response(response: 422, description: 'A name or an address that was sent was empty.')]
    public function update(int $id, int $locationId, Request $request): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, self::MSG_CANNOT_MANAGE, $this->authService, $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $invalid = $this->validateFields($body, false);
        if ($invalid !== null) {
            return $invalid;
        }

        $updated = $this->locations->update($locationId, $this->principalIdFor($id), $body);
        if ($updated === null) {
            return $this->locationNotFound();
        }

        return new JsonResponse(['data' => ['location' => $updated['location']]]);
    }

    #[OA\Delete(
        path: '/api/v1/groups/{id}/locations/{locationId}',
        summary: "Delete one of a group's saved locations",
        tags: ['Groups'],
    )]
    #[OA\Response(response: 200, description: 'Deleted.')]
    #[OA\Response(response: 403, description: 'The caller is a member of the group but not an owner or admin.')]
    #[OA\Response(response: 404, description: 'The group or the location does not exist, or the caller is not a member.')]
    public function destroy(int $id, int $locationId): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, self::MSG_CANNOT_MANAGE, $this->authService, $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        if (!$this->locations->delete($locationId, $this->principalIdFor($id))) {
            return $this->locationNotFound();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * The principal a write hangs on. `ensureGroupPrincipal()` because a
     * write needs a principal id that is on the row — one that was merely
     * looked up may not be there.
     */
    private function principalIdFor(int $groupId): int
    {
        return (int) $this->principalService->ensureGroupPrincipal($groupId)->id;
    }

    /**
     * The name and the address must be present and non-empty. On a POST
     * both are required; on a PATCH only the keys that were sent are
     * checked.
     *
     * @param  array<string, mixed> $body
     */
    private function validateFields(array $body, bool $requireBoth): ?JsonResponse
    {
        foreach (['name', 'address'] as $field) {
            if (!array_key_exists($field, $body)) {
                if ($requireBoth) {
                    return $this->unprocessable('VALIDATION_ERROR', $field . ' is required.');
                }
                continue;
            }

            if (trim((string) $body[$field]) !== '') {
                continue;
            }

            return $this->unprocessable(
                'VALIDATION_ERROR',
                $requireBoth
                    ? $field . ' is required.'
                    : $field . ' is required and cannot be empty.',
            );
        }

        return null;
    }

    private function locationNotFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => self::MSG_LOCATION_NOT_FOUND]],
            Response::HTTP_NOT_FOUND,
        );
    }
}
