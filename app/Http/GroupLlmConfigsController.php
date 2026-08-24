<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigServiceInterface;
use Spora\Services\LlmConfigValidator;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LLM-config CRUD scoped to a group's group-principal. Reuses
 * {@see LlmConfigValidator} and {@see LLMConfigServiceInterface} so the
 * same request shapes / validation / response shapes that
 * {@see LLMConfigController} emits carry over verbatim — the frontend
 * can reuse its admin LLM-config table component unchanged.
 *
 * Endpoints:
 *   GET    /api/v1/groups/{id}/llm-configs
 *   POST   /api/v1/groups/{id}/llm-configs
 *   PATCH  /api/v1/groups/{id}/llm-configs/{cid}
 *   DELETE /api/v1/groups/{id}/llm-configs/{cid}
 *   POST   /api/v1/groups/{id}/llm-configs/{cid}/set-default
 *
 * Authorisation: read uses `callerCanSeeGroup()` (members can list);
 * write uses `callerCanManageGroup()` (owner / admin / global admin).
 * Non-members receive 404 (existence-hiding, not 403).
 *
 * The set-default action is per-principal: when a group config is
 * promoted, every other `is_default = true` row sharing the same
 * `principal_id` is cleared so the "only one default per group"
 * invariant holds. The global set-default path stays untouched (it is
 * admin-only and operates on `is_global = true` rows).
 */
final class GroupLlmConfigsController
{
    use JsonControllerHelpers;
    use GroupAuthorizationTrait;

    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';
    private const MSG_CONFIG_NOT_FOUND = 'Configuration not found.';
    private const DB_TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly AuthService $authService,
        private readonly LLMConfigServiceInterface $llmConfigService,
        private readonly LlmConfigValidator $validator,
        private readonly PrincipalService $principalService,
    ) {}

    public function index(int $id): JsonResponse
    {
        $resolved = $this->resolveReadableGroup($id, $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        $rows = LLMDriverConfiguration::where('principal_id', $principalId)
            ->orderBy('name')
            ->get();

        return new JsonResponse(['data' => [
            'configs' => array_map(
                fn(LLMDriverConfiguration $c): array => $this->llmConfigService->configResource($c),
                $rows->all(),
            ),
        ]]);
    }

    public function store(int $id, Request $request): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, 'Only group owners or admins can create LLM configs.', $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        $body = $this->validatedStoreBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->createConfig($principalId, $userId, $body);
    }

    public function update(int $id, int $cid, Request $request): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, 'Only group owners or admins can edit LLM configs.', $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        return $this->performScopedUpdate($cid, $principalId, $userId, $request);
    }

    public function destroy(int $id, int $cid): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, 'Only group owners or admins can delete LLM configs.', $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        $error = $this->deleteScopedConfigOrFail($cid, $principalId);
        if ($error !== null) {
            return $error;
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    public function setDefault(int $id, int $cid): JsonResponse
    {
        $resolved = $this->resolveWritableGroup($id, 'Only group owners or admins can set the default LLM config.', $this->principalService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, ] = $resolved;

        $config = $this->setDefaultScopedConfigOrFail($cid, $principalId);
        if ($config instanceof JsonResponse) {
            return $config;
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($config)]]);
    }

    private function findScopedConfig(int $cid, int $principalId): ?LLMDriverConfiguration
    {
        return LLMDriverConfiguration::where('id', $cid)
            ->where('principal_id', $principalId)
            ->first();
    }

    /**
     * Decode JSON body → run store validation → return the body on
     * success or the validator's first failure as a JsonResponse.
     */
    private function validatedStoreBodyOrFail(Request $request): array|JsonResponse
    {
        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->validator->validateStoreBody($body) ?? $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createConfig(int $principalId, int $userId, array $body): JsonResponse
    {
        // Materialise the caller's user-principal first so
        // {@see PrincipalResolver::visiblePrincipalIds()} returns the
        // group-principal we want to write under (the resolver short-circuits
        // to `[]` when the user-principal row is missing, which would
        // otherwise make the cross-call approval gate in
        // {@see LLMConfigPersistence::createConfiguration()} throw).
        $this->principalService->ensureUserPrincipal($userId);

        // Force principal_id = group-principal; ignore any caller-supplied
        // value so the write can't be redirected to a different principal.
        $body['principal_id'] = $principalId;
        $data = $this->validator->prepareStoreData($body, $userId, true);
        $data['principal_id'] = $principalId;
        $data['is_global'] = false;

        $config = $this->llmConfigService->createConfiguration($userId, $data, true);
        if ($config === null) {
            return $this->validator->storeCreationError($data, true);
        }

        return new JsonResponse(
            ['data' => ['config' => $this->llmConfigService->configResource($config)]],
            Response::HTTP_CREATED,
        );
    }

    private function performScopedUpdate(int $cid, int $principalId, int $userId, Request $request): JsonResponse
    {
        $config = $this->findScopedConfig($cid, $principalId);
        if ($config === null) {
            return $this->notFound('NOT_FOUND', self::MSG_CONFIG_NOT_FOUND);
        }

        $body = $this->decodeAndValidateUpdateBody($request, $config);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->applyUpdatePayload($cid, $userId, $body);
    }

    private function decodeAndValidateUpdateBody(Request $request, LLMDriverConfiguration $config): array|JsonResponse
    {
        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $nameError = $this->validator->validateUpdateName($body);
        if ($nameError !== null) {
            return $nameError;
        }

        return $this->validator->validateUpdateSettings($body, $config) ?? $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyUpdatePayload(int $cid, int $userId, array $body): JsonResponse
    {
        $data = $this->validator->prepareUpdateData($body);
        $updated = $this->llmConfigService->updateConfiguration($cid, $userId, $data, true);
        if ($updated === null) {
            return $this->validator->forbidden();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($updated)]]);
    }

    /**
     * Returns the JsonResponse to surface when the cid is not in
     * this group's principal, or `null` on a successful delete.
     */
    private function deleteScopedConfigOrFail(int $cid, int $principalId): ?JsonResponse
    {
        $config = $this->findScopedConfig($cid, $principalId);
        if ($config === null) {
            return $this->notFound('NOT_FOUND', self::MSG_CONFIG_NOT_FOUND);
        }

        Capsule::table('llm_driver_configurations')
            ->where('id', $cid)
            ->delete();

        return null;
    }

    /**
     * Scope-check → clear any other default → promote the target → return
     * the fresh row. Returns JsonResponse on scope miss.
     */
    private function setDefaultScopedConfigOrFail(int $cid, int $principalId): LLMDriverConfiguration|JsonResponse
    {
        return Capsule::connection()->transaction(
            function () use ($cid, $principalId): LLMDriverConfiguration|JsonResponse {
                $config = LLMDriverConfiguration::where('id', $cid)
                    ->where('principal_id', $principalId)
                    ->lockForUpdate()
                    ->first();
                if ($config === null) {
                    return $this->notFound('NOT_FOUND', self::MSG_CONFIG_NOT_FOUND);
                }

                Capsule::table('llm_driver_configurations')
                    ->where('principal_id', $principalId)
                    ->where('is_default', true)
                    ->update(['is_default' => false, 'updated_at' => date(self::DB_TIMESTAMP_FORMAT)]);

                Capsule::table('llm_driver_configurations')
                    ->where('id', $cid)
                    ->update(['is_default' => true, 'updated_at' => date(self::DB_TIMESTAMP_FORMAT)]);

                return LLMDriverConfiguration::find($cid);
            },
        );
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
}
