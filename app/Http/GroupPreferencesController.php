<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\Group;
use Spora\Models\PrincipalPreference;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET / PUT` for the principal_preferences row that belongs to a group's
 * group-principal. There is at most one row per principal (the table's
 * UNIQUE(principal_id) index enforces that), so PUT is an upsert — first
 * call creates, subsequent calls update in place.
 *
 * Authorisation: read uses {@see GroupController::callerCanSeeGroup()}
 * (members can read); write uses
 * {@see GroupAuthorizationTrait::callerCanManageGroup()}
 * (owner / admin / global admin only). Non-members receive a 404 so
 * group ids stay non-probeable.
 *
 * Endpoints:
 *   GET  /api/v1/groups/{id}/preferences
 *   PUT  /api/v1/groups/{id}/preferences
 */
final class GroupPreferencesController
{
    use JsonControllerHelpers;
    use GroupAuthorizationTrait;

    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';
    private const MSG_GROUP_NOT_FOUND = 'Group not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly PrincipalService $principalService,
    ) {}

    public function show(int $id): JsonResponse
    {
        $resolved = $this->resolvePrincipalForCaller($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        return $this->respondWithPreference($principalId, $userId);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $resolved = $this->resolvePrincipalForCaller($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        if (!$this->callerCanManageGroup($id, $userId, $this->authService)) {
            return $this->forbidden('FORBIDDEN', 'Only group owners or admins can edit preferences.');
        }

        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $configId = $this->validateAndExtractConfigId($body);
        if ($configId instanceof JsonResponse) {
            return $configId;
        }

        $this->upsertPreference($principalId, $configId);

        return $this->respondWithPreference($principalId, $userId);
    }

    /**
     * Resolve the caller's auth + the group's principal id, OR a
     * 401/404 short-circuit. Returns `[principalId, userId]` when both
     * the caller is authenticated and authorised to read the group;
     * the controller short-circuits on the JsonResponse.
     *
     * @return array{0: int, 1: int}|JsonResponse
     */
    private function resolvePrincipalForCaller(int $id): array|JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $group = Group::find($id);
        if ($group === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $principal = $this->principalService->principalForGroup($id);
        if ($principal === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        if (!$this->callerCanSeeGroup($id, (int) $userId)) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return [(int) $principal->id, (int) $userId];
    }

    private function callerCanSeeGroup(int $groupId, int $userId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }
        return Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function respondWithPreference(int $principalId, int $userId): JsonResponse
    {
        $row = PrincipalPreference::where('principal_id', $principalId)->first();

        if ($row === null) {
            return new JsonResponse([
                'data' => [
                    'preference' => [
                        'principal_id'           => $principalId,
                        'preferred_llm_config_id' => null,
                    ],
                ],
            ]);
        }

        return new JsonResponse([
            'data' => [
                'preference' => [
                    'principal_id'           => (int) $row->principal_id,
                    'preferred_llm_config_id' => $row->preferred_llm_config_id !== null
                        ? (int) $row->preferred_llm_config_id
                        : null,
                    'updated_at'             => $row->updated_at->format(DateTimeInterface::ATOM),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBody(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return int|null|JsonResponse
     */
    private function validateAndExtractConfigId(array $body): int|null|JsonResponse
    {
        if (!array_key_exists('preferred_llm_config_id', $body)) {
            return $this->unprocessable('VALIDATION_ERROR', 'preferred_llm_config_id is required (may be null).');
        }
        $value = $body['preferred_llm_config_id'];
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value <= 0) {
            return $this->unprocessable('VALIDATION_ERROR', 'preferred_llm_config_id must be a positive integer or null.');
        }
        return $value;
    }

    private function upsertPreference(int $principalId, ?int $configId): void
    {
        $existing = PrincipalPreference::where('principal_id', $principalId)->first();
        if ($existing !== null) {
            $existing->preferred_llm_config_id = $configId;
            $existing->save();
            return;
        }

        $row = new PrincipalPreference();
        $row->principal_id = $principalId;
        $row->preferred_llm_config_id = $configId;
        $row->save();
    }
}
