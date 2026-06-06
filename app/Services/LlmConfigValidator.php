<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Models\LLMDriverConfiguration;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validation and authorization helpers for the LLM config HTTP layer.
 *
 * Lives in the Services namespace because the controller's private helpers
 * had grown to over twenty methods (SonarQube S1448). Owning validation,
 * authorization, and request-to-entity data shaping here keeps
 * {@see \Spora\Http\LLMConfigController} a thin HTTP layer with fewer than
 * twenty methods and lets every method here stay under the S1142
 * three-return limit.
 *
 * The class is intentionally framework-aware (it returns {@see JsonResponse}
 * for error paths) because every caller is a controller. Returning a generic
 * result type would just push the mapping code back into the controller.
 */
final class LlmConfigValidator
{
    public function __construct(
        private readonly LLMConfigServiceInterface $service,
    ) {}

    // ---------------------------------------------------------------------
    // POST /llm-configs (create)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    public function validateStoreBody(array $body): ?JsonResponse
    {
        $nameError = $this->validateStoreName($body);
        if ($nameError !== null) {
            return $nameError;
        }

        $driverError = $this->validateStoreDriverClass($body);
        if ($driverError !== null) {
            return $driverError;
        }

        return $this->validateStoreSettings($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateStoreName(array $body): ?JsonResponse
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'Name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateStoreDriverClass(array $body): ?JsonResponse
    {
        $driverClass = trim((string) ($body['driver_class'] ?? ''));
        if ($driverClass === '' || ! class_exists($driverClass)) {
            return $this->error('VALIDATION_ERROR', 'Invalid driver_class.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateStoreSettings(array $body): ?JsonResponse
    {
        $rawSettings = $body['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $validationError = $this->validateSettings($settings, $this->getSchemaForDriver(trim((string) ($body['driver_class'] ?? ''))));

        if ($validationError === null) {
            return null;
        }

        return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function prepareStoreData(array $body): array
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

    /**
     * @param array<string, mixed> $data
     */
    public function storeCreationError(array $data, bool $isAdmin): JsonResponse
    {
        if (!empty($data['is_global']) && !$isAdmin) {
            return $this->error('VALIDATION_ERROR', 'Only admins can create global configurations.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->error('VALIDATION_ERROR', 'Failed to create configuration.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // ---------------------------------------------------------------------
    // PUT /llm-configs/{id} (update) — also authorizes the access
    // ---------------------------------------------------------------------

    /**
     * Returns the configuration the current user can modify, or a
     * JsonResponse describing why access was denied.
     */
    public function resolveAccessibleConfig(int $id, ?int $userId, bool $isAdmin): LLMDriverConfiguration|JsonResponse
    {
        $config = $this->service->getConfiguration($id, $userId);
        if ($config !== null) {
            return $config;
        }

        $existingConfig = $this->service->findConfiguration($id);
        if ($existingConfig === null) {
            return $this->notFound();
        }

        return $this->authorizeNonOwnerAccess($existingConfig, $userId, $isAdmin);
    }

    private function authorizeNonOwnerAccess(LLMDriverConfiguration $config, ?int $userId, bool $isAdmin): LLMDriverConfiguration|JsonResponse
    {
        if (!$isAdmin && $config->is_global) {
            return $this->forbidden();
        }
        if ($config->user_id !== null && $config->user_id !== $userId) {
            return $this->notFound();
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function validateUpdateName(array $body): ?JsonResponse
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
     * @param array<string, mixed> $body
     */
    public function validateUpdateSettings(array $body, LLMDriverConfiguration $config): ?JsonResponse
    {
        if (!isset($body['settings']) || !is_array($body['settings']) || array_is_list($body['settings'])) {
            return null;
        }

        $schema = $this->getSchemaForDriver($config->driver_class);
        $existing = $this->service->decodeSettings($config->driver_class, $config->getRawOriginal('settings') ?? '');
        $merged = array_merge($existing, $body['settings']);
        $validationError = $this->validateSettings($merged, $schema);
        if ($validationError === null) {
            return null;
        }

        return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function prepareUpdateData(array $body): array
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

    // ---------------------------------------------------------------------
    // Driver schema & settings validation
    // ---------------------------------------------------------------------

    /**
     * @return list<array>
     */
    public function getSchemaForDriver(string $driverClass): array
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

    /**
     * @param array<string, mixed> $settings
     * @param list<array> $schema
     */
    public function validateSettings(array $settings, array $schema): ?string
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

    // ---------------------------------------------------------------------
    // Error response helpers — kept here so the controller stays thin
    // ---------------------------------------------------------------------

    public function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    public function notFound(): JsonResponse
    {
        return $this->error('NOT_FOUND', 'Configuration not found.', Response::HTTP_NOT_FOUND);
    }

    public function forbidden(): JsonResponse
    {
        return $this->error('FORBIDDEN', 'You do not have permission to perform this action.', Response::HTTP_FORBIDDEN);
    }
}
