<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\Group;
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
    private const MSG_GROUP_NOT_FOUND = 'Group not found.';
    private const MSG_CONFIG_NOT_FOUND = 'Configuration not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly LLMConfigServiceInterface $llmConfigService,
        private readonly LlmConfigValidator $validator,
        private readonly PrincipalService $principalService,
    ) {}

    public function index(int $id): JsonResponse
    {
        $resolved = $this->resolveGroupAndPrincipal($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

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
        $resolved = $this->resolveGroupAndPrincipal($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        if (!$this->callerCanManageGroup($id, $userId, $this->authService)) {
            return $this->forbidden('FORBIDDEN', 'Only group owners or admins can create LLM configs.');
        }

        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validation = $this->validator->validateStoreBody($body);
        if ($validation !== null) {
            return $validation;
        }

        return $this->createConfig($principalId, $userId, $body);
    }

    public function update(int $id, int $cid, Request $request): JsonResponse
    {
        $resolved = $this->resolveGroupAndPrincipal($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        if (!$this->callerCanManageGroup($id, $userId, $this->authService)) {
            return $this->forbidden('FORBIDDEN', 'Only group owners or admins can edit LLM configs.');
        }

        $body = $this->decodeBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->performUpdate($id, $cid, $principalId, $userId, $body);
    }

    public function destroy(int $id, int $cid): JsonResponse
    {
        $resolved = $this->resolveGroupAndPrincipal($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        if (!$this->callerCanManageGroup($id, $userId, $this->authService)) {
            return $this->forbidden('FORBIDDEN', 'Only group owners or admins can delete LLM configs.');
        }

        $config = $this->findScopedConfig($cid, $principalId);
        if ($config === null) {
            return $this->notFound('NOT_FOUND', self::MSG_CONFIG_NOT_FOUND);
        }

        Capsule::table('llm_driver_configurations')
            ->where('id', $cid)
            ->delete();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    public function setDefault(int $id, int $cid): JsonResponse
    {
        $resolved = $this->resolveGroupAndPrincipal($id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$principalId, $userId] = $resolved;

        if (!$this->callerCanManageGroup($id, $userId, $this->authService)) {
            return $this->forbidden('FORBIDDEN', 'Only group owners or admins can set the default LLM config.');
        }

        $config = $this->findScopedConfig($cid, $principalId);
        if ($config === null) {
            return $this->notFound('NOT_FOUND', self::MSG_CONFIG_NOT_FOUND);
        }

        Capsule::table('llm_driver_configurations')
            ->where('principal_id', $principalId)
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => date('Y-m-d H:i:s')]);

        Capsule::table('llm_driver_configurations')
            ->where('id', $cid)
            ->update(['is_default' => true, 'updated_at' => date('Y-m-d H:i:s')]);

        $fresh = LLMDriverConfiguration::find($cid);
        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($fresh)]]);
    }

    /**
     * @return array{0: int, 1: int}|JsonResponse
     */
    private function resolveGroupAndPrincipal(int $id): array|JsonResponse
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

    private function findScopedConfig(int $cid, int $principalId): ?LLMDriverConfiguration
    {
        return LLMDriverConfiguration::where('id', $cid)
            ->where('principal_id', $principalId)
            ->first();
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

    /**
     * @param array<string, mixed> $body
     */
    private function performUpdate(int $id, int $cid, int $principalId, int $userId, array $body): JsonResponse
    {
        $config = $this->findScopedConfig($cid, $principalId);
        if ($config === null) {
            return $this->notFound('NOT_FOUND', self::MSG_CONFIG_NOT_FOUND);
        }

        $nameError = $this->validator->validateUpdateName($body);
        if ($nameError !== null) {
            return $nameError;
        }

        $settingsError = $this->validator->validateUpdateSettings($body, $config);
        if ($settingsError !== null) {
            return $settingsError;
        }

        $data = $this->validator->prepareUpdateData($body);
        $updated = $this->llmConfigService->updateConfiguration($cid, $userId, $data, true);
        if ($updated === null) {
            return $this->validator->forbidden();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($updated)]]);
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
