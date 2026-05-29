<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Services\LLMConfigServiceInterface;
use Spora\Tools\Attributes\ToolSetting;
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
 */
final class LLMConfigController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly LLMConfigServiceInterface $llmConfigService,
    ) {}

    // ── Schema discovery ────────────────────────────────────────────────────

    /**
     * GET /llm-drivers
     *
     * Returns all registered LLM driver classes with their settings schemas.
     * Used by the UI to render configuration forms dynamically.
     */
    public function drivers(Request $request): JsonResponse
    {
        $drivers = $this->llmConfigService->getDrivers();

        return new JsonResponse(['data' => ['drivers' => $drivers]]);
    }

    // ── Config CRUD ──────────────────────────────────────────────────────────

    /**
     * GET /llm-configs
     *
     * Returns the current user's personal configs merged with all global configs
     * (for browsing and selecting defaults). Use globalConfigs() for admin management.
     */
    public function index(Request $request): JsonResponse
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
    public function globalConfigs(Request $request): JsonResponse
    {

        $configs = $this->llmConfigService->getGlobalConfigurations();

        return new JsonResponse(['data' => ['configs' => $configs]]);
    }

    /**
     * GET /llm-configs/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $config = $this->llmConfigService->getConfiguration($id, $userId);
        if ($config === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($config)]]);
    }

    /**
     * POST /llm-configs
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'Name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $driverClass = trim((string) ($body['driver_class'] ?? ''));
        if ($driverClass === '' || ! class_exists($driverClass)) {
            return $this->error('VALIDATION_ERROR', 'Invalid driver_class.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Validate required fields against schema
        $schema = $this->getSchemaForDriver($driverClass);
        $rawSettings = $body['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $validationError = $this->validateSettings($settings, $schema);
        if ($validationError !== null) {
            return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $body;
        $data['name'] = $name;
        $data['driver_class'] = $driverClass;
        $data['settings'] = $settings;
        $data['is_global'] = !empty($body['is_global']);
        $data['is_default'] = !empty($body['is_default']);
        if (isset($body['context_window'])) {
            $data['context_window'] = (int) $body['context_window'];
        }
        if (isset($body['max_tokens_output'])) {
            $data['max_tokens_output'] = (int) $body['max_tokens_output'];
        }

        $config = $this->llmConfigService->createConfiguration($userId, $data, $isAdmin);
        if ($config === null) {
            if (!empty($data['is_global']) && !$isAdmin) {
                return $this->error('VALIDATION_ERROR', 'Only admins can create global configurations.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            return $this->error('VALIDATION_ERROR', 'Failed to create configuration.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['data' => ['config' => $this->llmConfigService->configResource($config)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * PUT /llm-configs/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();

        // Check if config exists and is accessible
        $config = $this->llmConfigService->getConfiguration($id, $userId);
        if ($config === null) {
            // Check if it might be a global config (admins can access it)
            $existingConfig = $this->llmConfigService->findConfiguration($id);
            if ($existingConfig === null) {
                return $this->notFound();
            }
            // Config exists but relates to a global config that the user is not an admin for - deny access
            if (!$isAdmin && $existingConfig->is_global) {
                return $this->forbidden();
            }
            // Config belongs to another user - return not found to avoid enumeration
            if ($existingConfig->user_id !== null && $existingConfig->user_id !== $userId) {
                return $this->notFound();
            }
            // Admin trying to update global config - reload as admin access
            $config = $existingConfig;
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return $this->error('VALIDATION_ERROR', 'Name cannot be empty.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (isset($body['settings']) && is_array($body['settings']) && !array_is_list($body['settings'])) {
            $schema = $this->getSchemaForDriver($config->driver_class);
            $existing = $this->llmConfigService->decodeSettings($config->driver_class, $config->getRawOriginal('settings') ?? '');
            $merged = array_merge($existing, $body['settings']);
            $validationError = $this->validateSettings($merged, $schema);
            if ($validationError !== null) {
                return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $data = $body;
        if (isset($body['context_window'])) {
            $data['context_window'] = (int) $body['context_window'];
        }
        if (isset($body['max_tokens_output'])) {
            $data['max_tokens_output'] = (int) $body['max_tokens_output'];
        }

        $updatedConfig = $this->llmConfigService->updateConfiguration($id, $userId, $data, $isAdmin);
        if ($updatedConfig === null) {
            return $this->forbidden();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($updatedConfig)]]);
    }

    /**
     * DELETE /llm-configs/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();

        // Check if config exists and belongs to another user (return 404 to avoid enumeration)
        $existingConfig = $this->llmConfigService->findConfiguration($id);
        if ($existingConfig !== null && !$isAdmin && !$existingConfig->is_global && $existingConfig->user_id !== $userId) {
            return $this->notFound();
        }

        $deleted = $this->llmConfigService->deleteConfiguration($id, $userId, $isAdmin);
        if (!$deleted) {
            return $this->forbidden();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * POST /llm-configs/{id}/set-default
     */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();

        $config = $this->llmConfigService->setDefaultConfiguration($id, $userId, $isAdmin);
        if ($config === null) {
            return $this->forbidden();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($config)]]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return list<array> */
    private function getSchemaForDriver(string $driverClass): array
    {
        if (! class_exists($driverClass)) {
            return [];
        }

        $schema = [];
        foreach ((new ReflectionClass($driverClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            $schema[] = [
                'key' => $setting->key,
                'label' => $setting->label,
                'type' => $setting->type,
                'description' => $setting->description,
                'default' => $setting->default,
                'required' => $setting->required,
                'scope' => $setting->scope,
                'options' => $setting->options,
                'validation' => $setting->validation,
            ];
        }
        return $schema;
    }

    private function validateSettings(array $settings, array $schema): ?string
    {
        foreach ($schema as $field) {
            $key = $field['key'];
            $required = $field['required'] ?? false;

            if ($required && (! array_key_exists($key, $settings) || $settings[$key] === '')) {
                return "Field '{$field['label']}' is required.";
            }

            if (array_key_exists($key, $settings) && $settings[$key] !== '') {
                $value = (string) $settings[$key];
                $validation = $field['validation'] ?? '';
                if ($validation !== '' && !preg_match($validation, $value)) {
                    return "Field '{$field['label']}' has an invalid value.";
                }
            }
        }
        return null;
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Configuration not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'FORBIDDEN', 'message' => 'You do not have permission to perform this action.']],
            Response::HTTP_FORBIDDEN,
        );
    }
}
