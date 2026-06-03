<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Models\LLMDriverConfiguration;
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

        $validationError = $this->validateStoreBody($body);
        if ($validationError !== null) {
            return $validationError;
        }

        $data = $this->prepareStoreData($body);
        $config = $this->llmConfigService->createConfiguration($userId, $data, $isAdmin);
        if ($config === null) {
            return $this->storeCreationError($data, $isAdmin);
        }

        return new JsonResponse(
            ['data' => ['config' => $this->llmConfigService->configResource($config)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Validate the body of a create request. Returns an error response on failure.
     */
    private function validateStoreBody(array $body): ?JsonResponse
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'Name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $driverClass = trim((string) ($body['driver_class'] ?? ''));
        if ($driverClass === '' || ! class_exists($driverClass)) {
            return $this->error('VALIDATION_ERROR', 'Invalid driver_class.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rawSettings = $body['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $validationError = $this->validateSettings($settings, $this->getSchemaForDriver($driverClass));
        if ($validationError !== null) {
            return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    /**
     * Build the data array passed to the LLM config service for creation.
     *
     * @return array<string, mixed>
     */
    private function prepareStoreData(array $body): array
    {
        $data = $body;
        $data['name'] = trim((string) ($body['name'] ?? ''));
        $data['driver_class'] = trim((string) ($body['driver_class'] ?? ''));
        $rawSettings = $body['settings'] ?? null;
        $data['settings'] = is_array($rawSettings) ? $rawSettings : [];
        $data['is_global'] = !empty($body['is_global']);
        $data['is_default'] = !empty($body['is_default']);
        if (isset($body['context_window'])) {
            $data['context_window'] = (int) $body['context_window'];
        }
        if (isset($body['max_tokens_output'])) {
            $data['max_tokens_output'] = (int) $body['max_tokens_output'];
        }
        return $data;
    }

    private function storeCreationError(array $data, bool $isAdmin): JsonResponse
    {
        if (!empty($data['is_global']) && !$isAdmin) {
            return $this->error('VALIDATION_ERROR', 'Only admins can create global configurations.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return $this->error('VALIDATION_ERROR', 'Failed to create configuration.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * PUT /llm-configs/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $isAdmin = $this->authService->isAdmin();

        $config = $this->resolveAccessibleConfig($id, $userId, $isAdmin);
        if ($config instanceof JsonResponse) {
            return $config;
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $nameError = $this->validateUpdateName($body);
        if ($nameError !== null) {
            return $nameError;
        }

        $settingsError = $this->validateUpdateSettings($body, $config);
        if ($settingsError !== null) {
            return $settingsError;
        }

        $data = $this->prepareUpdateData($body);

        $updatedConfig = $this->llmConfigService->updateConfiguration($id, $userId, $data, $isAdmin);
        if ($updatedConfig === null) {
            return $this->forbidden();
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($updatedConfig)]]);
    }

    /**
     * Resolve a config the current user can modify, or return the appropriate error response.
     */
    private function resolveAccessibleConfig(int $id, ?int $userId, bool $isAdmin): LLMDriverConfiguration|JsonResponse
    {
        $config = $this->llmConfigService->getConfiguration($id, $userId);
        if ($config !== null) {
            return $config;
        }

        $existingConfig = $this->llmConfigService->findConfiguration($id);
        if ($existingConfig === null) {
            return $this->notFound();
        }
        if (!$isAdmin && $existingConfig->is_global) {
            return $this->forbidden();
        }
        if ($existingConfig->user_id !== null && $existingConfig->user_id !== $userId) {
            return $this->notFound();
        }

        return $existingConfig;
    }

    /**
     * Validate the optional 'name' field on update.
     */
    private function validateUpdateName(array $body): ?JsonResponse
    {
        if (!isset($body['name'])) {
            return null;
        }
        if (trim((string) $body['name']) === '') {
            return $this->error('VALIDATION_ERROR', 'Name cannot be empty.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return null;
    }

    /**
     * Validate the optional 'settings' payload against the driver's schema.
     */
    private function validateUpdateSettings(array $body, LLMDriverConfiguration $config): ?JsonResponse
    {
        if (!isset($body['settings']) || !is_array($body['settings']) || array_is_list($body['settings'])) {
            return null;
        }

        $schema = $this->getSchemaForDriver($config->driver_class);
        $existing = $this->llmConfigService->decodeSettings($config->driver_class, $config->getRawOriginal('settings') ?? '');
        $merged = array_merge($existing, $body['settings']);
        $validationError = $this->validateSettings($merged, $schema);
        if ($validationError !== null) {
            return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return null;
    }

    /**
     * Cast integer-valued fields on the update payload.
     *
     * @return array<string, mixed>
     */
    private function prepareUpdateData(array $body): array
    {
        $data = $body;
        if (isset($body['context_window'])) {
            $data['context_window'] = (int) $body['context_window'];
        }
        if (isset($body['max_tokens_output'])) {
            $data['max_tokens_output'] = (int) $body['max_tokens_output'];
        }
        return $data;
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
