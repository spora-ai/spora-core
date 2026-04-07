<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;
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
        private readonly LLMConfigService $llmConfigService,
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
     */
    public function index(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $configs = LLMDriverConfiguration::where('user_id', $userId)
            ->get()
            ->map(fn(LLMDriverConfiguration $config): array => $this->configResource($config))
            ->all();

        return new JsonResponse(['data' => ['configs' => $configs]]);
    }

    /**
     * GET /llm-configs/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $config = LLMDriverConfiguration::where('id', $id)->where('user_id', $userId)->first();
        if ($config === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['config' => $this->configResource($config)]]);
    }

    /**
     * POST /llm-configs
     */
    public function store(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

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

        $rawSettings = $body['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];

        // Validate required fields against schema
        $schema = $this->getSchemaForDriver($driverClass);
        $validationError = $this->validateSettings($settings, $schema);
        if ($validationError !== null) {
            return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $config = new LLMDriverConfiguration();
        $config->user_id = $userId;
        $config->name = $name;
        $config->driver_class = $driverClass;
        $config->settings = $this->llmConfigService->encryptSettings($settings);
        $config->is_default = !empty($body['is_default']);
        if ($config->is_default) {
            LLMDriverConfiguration::where('user_id', $userId)->where('is_default', true)->update(['is_default' => false]);
        }
        $config->save();

        return new JsonResponse(
            ['data' => ['config' => $this->configResource($config)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * PUT /llm-configs/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $config = LLMDriverConfiguration::where('id', $id)->where('user_id', $userId)->first();
        if ($config === null) {
            return $this->notFound();
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
            $config->name = $name;
        }

        if (isset($body['settings']) && is_array($body['settings'])) {
            $schema = $this->getSchemaForDriver($config->driver_class);
            $validationError = $this->validateSettings($body['settings'], $schema);
            if ($validationError !== null) {
                return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $config->settings = $this->llmConfigService->encryptSettings($body['settings']);
        }

        $config->save();

        return new JsonResponse(['data' => ['config' => $this->configResource($config)]]);
    }

    /**
     * DELETE /llm-configs/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $config = LLMDriverConfiguration::where('id', $id)->where('user_id', $userId)->first();
        if ($config === null) {
            return $this->notFound();
        }

        // Unset any agents using this config
        Agent::where('llm_driver_config_id', $id)
            ->update(['llm_driver_config_id' => null]);

        $config->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * POST /llm-configs/{id}/set-default
     */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $config = LLMDriverConfiguration::where('id', $id)->where('user_id', $userId)->first();
        if ($config === null) {
            return $this->notFound();
        }

        // Clear existing default for this user
        LLMDriverConfiguration::where('user_id', $userId)->where('is_default', true)->update(['is_default' => false]);

        $config->is_default = true;
        $config->save();

        return new JsonResponse(['data' => ['config' => $this->configResource($config)]]);
    }

    // ── Resource transformation ─────────────────────────────────────────────

    /**
     * Transform a model into an API resource.
     *
     * @return array<string, mixed>
     */
    private function configResource(LLMDriverConfiguration $config): array
    {
        // Use getRawOriginal to bypass the 'array' cast which double-encodes the encrypted JSON
        $settings = $this->llmConfigService->decryptSettings($config->getRawOriginal('settings'));
        $schema = $this->getSchemaForDriver($config->driver_class);
        $masked = $this->llmConfigService->maskForApi($settings, $schema);

        return [
            'id' => $config->id,
            'name' => $config->name,
            'driver_class' => $config->driver_class,
            'driver_name' => $this->getDriverName($config->driver_class),
            'driver_display_name' => $this->getDriverDisplayName($config->driver_class),
            'settings' => $masked,
            'is_default' => $config->is_default,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ];
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
            ];
        }
        return $schema;
    }

    private function getDriverName(string $driverClass): string
    {
        if (! class_exists($driverClass)) {
            return $driverClass;
        }
        return $driverClass::getName();
    }

    private function getDriverDisplayName(string $driverClass): string
    {
        if (! class_exists($driverClass)) {
            return $driverClass;
        }
        return $driverClass::getDisplayName();
    }

    private function validateSettings(array $settings, array $schema): ?string
    {
        foreach ($schema as $field) {
            $key = $field['key'];
            $required = $field['required'] ?? false;

            if ($required && (! array_key_exists($key, $settings) || $settings[$key] === '')) {
                return "Field '{$field['label']}' is required.";
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
}
