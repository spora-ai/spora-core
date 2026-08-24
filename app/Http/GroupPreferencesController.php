<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeInterface;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\LLMDriverConfiguration;
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
 * Authorisation: read uses `callerCanSeeGroup()` (members can read);
 * write uses `callerCanManageGroup()` (owner / admin / global admin
 * only). Non-members receive a 404 so group ids stay non-probeable.
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

    public function __construct(
        private readonly AuthService $authService,
        private readonly PrincipalService $principalService,
    ) {}

    public function show(int $id): JsonResponse
    {
        $resolved = $this->resolveReadableGroup($id, $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        return $this->respondWithPreference($principalId);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, 'Only group owners or admins can edit preferences.', $this->principalService);
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
        return $this->assertConfigMatchesPrincipal($config, $principalId);
    }

    private function assertConfigMatchesPrincipal(LLMDriverConfiguration $config, int $principalId): ?JsonResponse
    {
        if ($config->is_global || (int) $config->principal_id === $principalId) {
            return null;
        }
        return $this->unprocessable('CONFIG_PRINCIPAL_MISMATCH', 'preferred_llm_config_id must be global or belong to the same principal as the group.');
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
