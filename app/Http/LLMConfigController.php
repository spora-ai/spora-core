<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigServiceInterface;
use Spora\Services\LlmConfigValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API for LLM Driver Configurations.
 *
 * Endpoints:
 *   GET    /llm-drivers                — registered driver classes + schemas (no auth required for schema discovery)
 *   GET    /llm-configs                — all configs for current user
 *   GET    /llm-configs/{id}           — single config
 *   POST   /llm-configs                — create config
 *   PUT    /llm-configs/{id}          — update config
 *   DELETE /llm-configs/{id}           — delete config
 *   POST   /llm-configs/{id}/set-default — set as global default
 *
 * Validation, authorization, and request shaping live in {@see LlmConfigValidator}
 * so this controller stays under the S1448 method-count limit. Public methods
 * follow the early-exit + helper pattern to stay under the S1142 return limit.
 */
final class LLMConfigController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly LLMConfigServiceInterface $llmConfigService,
        private readonly LlmConfigValidator $validator,
    ) {}

    /**
     * GET /llm-drivers
     *
     * Returns all registered LLM driver classes with their settings schemas.
     * Used by the UI to render configuration forms dynamically.
     */
    public function drivers(): JsonResponse
    {
        $drivers = $this->llmConfigService->getDrivers();

        return new JsonResponse(['data' => ['drivers' => $drivers]]);
    }

    /**
     * GET /llm-configs
     *
     * Returns the current user's personal configs merged with all global configs
     * (for browsing and selecting defaults). Use globalConfigs() for admin management.
     */
    public function index(): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $configs = $this->llmConfigService->getConfigurationsForUser($userId);

        return new JsonResponse(['data' => ['configs' => $configs]]);
    }

    /**
     * GET /llm-configs/global
     *
     * Returns all global configs (for admin management). Personal configs are excluded.
     */
    public function globalConfigs(): JsonResponse
    {
        $configs = $this->llmConfigService->getGlobalConfigurations();

        return new JsonResponse(['data' => ['configs' => $configs]]);
    }

    /**
     * GET /llm-configs/{id}
     */
    public function show(int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $config = $this->llmConfigService->getConfiguration($id, $userId);
        if ($config === null) {
            return $this->validator->notFound();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($config)]]);
    }

    /**
     * POST /llm-configs
     */
    public function store(Request $request): JsonResponse
    {
        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $validationError = $this->validator->validateStoreBody($body);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->createConfiguration($body);
    }

    /**
     * PUT /llm-configs/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $body = $this->decodeRequestBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->updateConfiguration($id, $body);
    }

    /**
     * DELETE /llm-configs/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();

        // Check if config exists and belongs to another user (return 404 to avoid enumeration)
        $existingConfig = $this->llmConfigService->findConfiguration($id);
        if ($existingConfig !== null && !$isAdmin && !$existingConfig->is_global && $existingConfig->user_id !== $userId) {
            return $this->validator->notFound();
        }

        $deleted = $this->llmConfigService->deleteConfiguration($id, $userId, $isAdmin);
        if (!$deleted) {
            return $this->validator->forbidden();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * POST /llm-configs/{id}/set-default
     */
    public function setDefault(int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();

        $config = $this->llmConfigService->setDefaultConfiguration($id, $userId, $isAdmin);
        if ($config === null) {
            return $this->validator->forbidden();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($config)]]);
    }

    // ---------------------------------------------------------------------
    // Helper methods — each owns one slice of the workflow so the public
    // methods above stay under the S1142 3-return limit.
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function createConfiguration(array $body): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();
        $data = $this->validator->prepareStoreData($body);
        $config = $this->llmConfigService->createConfiguration($userId, $data, $isAdmin);
        if ($config === null) {
            return $this->validator->storeCreationError($data, $isAdmin);
        }

        return new JsonResponse(
            ['data' => ['config' => $this->llmConfigService->configResource($config)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function updateConfiguration(int $id, array $body): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();

        $config = $this->validator->resolveAccessibleConfig($id, $userId, $isAdmin);
        if ($config instanceof JsonResponse) {
            return $config;
        }

        $validation = $this->validateUpdatePayload($body, $config);
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        return $this->performUpdate($id, $userId, $isAdmin, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateUpdatePayload(array $body, LLMDriverConfiguration $config): ?JsonResponse
    {
        $nameError = $this->validator->validateUpdateName($body);
        if ($nameError !== null) {
            return $nameError;
        }

        $settingsError = $this->validator->validateUpdateSettings($body, $config);
        if ($settingsError !== null) {
            return $settingsError;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function performUpdate(int $id, int $userId, bool $isAdmin, array $body): JsonResponse
    {
        $data = $this->validator->prepareUpdateData($body);
        $updatedConfig = $this->llmConfigService->updateConfiguration($id, $userId, $data, $isAdmin);
        if ($updatedConfig === null) {
            return $this->validator->forbidden();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($updatedConfig)]]);
    }

    /**
     * Decode the JSON body, returning a 400 JsonResponse on failure so
     * callers can write `if ($body instanceof JsonResponse) return $body;`
     * instead of nesting a try/catch in every endpoint.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeRequestBody(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->validator->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
