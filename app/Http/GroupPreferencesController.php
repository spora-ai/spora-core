<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\Group;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Principal;
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
        $resolved = $this->resolveReadableGroup($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        return $this->respondWithPreference($principalId);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, 'Only group owners or admins can edit preferences.');
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        $configId = $this->validatedConfigIdOrFail($request);
        if ($configId instanceof JsonResponse) {
            return $configId;
        }

        return $this->applyAndRespond($principalId, $configId);
    }

    /**
     * @return array{0: int, 1: int}|JsonResponse
     */
    private function resolveReadableGroup(int $id): array|JsonResponse
    {
        $userId = $this->requireCurrentUserIdOrFail();
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $principal = $this->loadGroupPrincipalIfVisible($id, $userId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        return [(int) $principal->id, $userId];
    }

    /**
     * @return array{0: int, 1: int}|JsonResponse
     */
    private function resolveWritableGroup(int $id, string $denyMessage): array|JsonResponse
    {
        $resolved = $this->resolveReadableGroup($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        if (!$this->callerCanManageGroup($id, $userId, $this->authService)) {
            return $this->forbidden('FORBIDDEN', $denyMessage);
        }

        return [$principalId, $userId];
    }

    /**
     * @return int|JsonResponse
     */
    private function requireCurrentUserIdOrFail(): int|JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }
        return (int) $userId;
    }

    private function loadGroupPrincipalIfVisible(int $id, int $userId): Principal|JsonResponse
    {
        $group = Group::find($id);
        if ($group === null || !$this->callerCanSeeGroup($id, $userId)) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        $principal = $this->principalService->principalForGroup($id);
        if ($principal === null) {
            return $this->notFound('GROUP_NOT_FOUND', self::MSG_GROUP_NOT_FOUND);
        }

        return $principal;
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

    private function respondWithPreference(int $principalId): JsonResponse
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
     * Decode + extract + validate the preferred config id from the body.
     *
     * @return int|null|JsonResponse
     */
    private function validatedConfigIdOrFail(Request $request): int|null|JsonResponse
    {
        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->validateAndExtractConfigId($body);
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
        return $this->parseStoredConfigId($body['preferred_llm_config_id']);
    }

    /**
     * @return int|null|JsonResponse
     */
    private function parseStoredConfigId(mixed $value): int|null|JsonResponse
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value <= 0) {
            return $this->unprocessable('VALIDATION_ERROR', 'preferred_llm_config_id must be a positive integer or null.');
        }
        return $value;
    }

    /**
     * Upsert the preference and return the resolved wire payload.
     */
    private function applyAndRespond(int $principalId, ?int $configId): JsonResponse
    {
        $error = $this->validateConfigBelongsToPrincipal($configId, $principalId);
        if ($error !== null) {
            return $error;
        }

        $this->upsertPreference($principalId, $configId);
        return $this->respondWithPreference($principalId);
    }

    /**
     * The `config_id` body field is the row's primary key, but the
     * row also has a `principal_id` (or `is_global = true`). Without
     * the scope check, a caller authorised to manage the group could
     * point the group's preference at another group's config or at a
     * personal config that belongs to a user-principal they don't
     * control. Refuse the mismatched pointer with a 422 so the
     * operator gets a clean error instead of a silently misrouted
     * preference.
     */
    private function validateConfigBelongsToPrincipal(?int $configId, int $principalId): ?JsonResponse
    {
        if ($configId === null) {
            return null;
        }

        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return $this->unprocessable('CONFIG_NOT_FOUND', 'preferred_llm_config_id does not reference an existing configuration.');
        }
        if (!$config->is_global && (int) $config->principal_id !== $principalId) {
            return $this->unprocessable('CONFIG_PRINCIPAL_MISMATCH', 'preferred_llm_config_id must be global or belong to the same principal as the group.');
        }
        return null;
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
