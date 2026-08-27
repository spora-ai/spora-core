<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\LLMDriverConfiguration;
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
 * Driver schema reflection + per-field value validation moved to
 * {@see LlmConfigSchemaValidator} so this class stays under the
 * twenty-method ceiling.
 *
 * The class is intentionally framework-aware (it returns {@see JsonResponse}
 * for error paths) because every caller is a controller. Returning a generic
 * result type would just push the mapping code back into the controller.
 */
final class LlmConfigValidator
{
    private readonly PrincipalService $principalService;
    private readonly PrincipalResolver $principalResolver;

    private readonly LlmConfigSchemaValidator $schemaValidator;

    public function __construct(
        private readonly LLMConfigServiceInterface $service,
        ?LlmConfigSchemaValidator $schemaValidator = null,
        ?PrincipalService $principalService = null,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->schemaValidator    = $schemaValidator ?? new LlmConfigSchemaValidator();
        $this->principalService  = $principalService ?? new PrincipalService(new PrincipalResolver());
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    // ---------------------------------------------------------------------
    // POST /llm-configs (create)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    public function validateStoreBody(array $body): ?JsonResponse
    {
        // Each helper returns the first failure (or null). Collect in one
        // pass so the orchestrator stays under the S1142 return-count cap.
        foreach (
            [
                $this->validateStoreName($body),
                $this->validateStoreDriverClass($body),
                $this->schemaValidator->validateLimits($body),
                $this->validateStoreSettings($body),
            ] as $error
        ) {
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Body validation for `PUT /llm-configs/{id}`. Closes the gap where the
     * update endpoint skipped body validation entirely — only name and
     * settings were checked. Now also enforces the limit bounds so a
     * request like `{"max_tokens_output": 9999999}` is rejected with a 422.
     */
    public function validateUpdateBody(array $body): ?JsonResponse
    {
        $nameError = $this->validateUpdateName($body);
        if ($nameError !== null) {
            return $nameError;
        }

        $limitsError = $this->schemaValidator->validateLimits($body);
        if ($limitsError !== null) {
            return $limitsError;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function validateLimits(array $body): ?JsonResponse
    {
        return $this->schemaValidator->validateLimits($body);
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
        $validationError = $this->schemaValidator->validateSettings(
            $settings,
            $this->schemaValidator->getSchemaForDriver(trim((string) ($body['driver_class'] ?? ''))),
        );

        if ($validationError === null) {
            return null;
        }

        return $this->error('VALIDATION_ERROR', $validationError, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function prepareStoreData(array $body, int $callerUserId, bool $isAdmin): array
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

        // Default principal_id to the caller's user-principal. Admin
        // callers can target any group; non-admins can only target their
        // own user-principal (or, by extension, a group they're an
        // owner/admin of).
        if (isset($body['principal_id']) && is_int($body['principal_id'])) {
            $requestedPrincipalId = $body['principal_id'];
            if (!$this->callerMayTargetPrincipal($callerUserId, $isAdmin, $requestedPrincipalId)) {
                $data['principal_id'] = $this->principalService->ensureUserPrincipal($callerUserId)->id;
            } else {
                $data['principal_id'] = $requestedPrincipalId;
            }
        } else {
            $data['principal_id'] = $this->principalService->ensureUserPrincipal($callerUserId)->id;
        }

        return $data;
    }

    /**
     * Auth gate for "who is allowed to write a config under principal X":
     * admin always; otherwise the requested principal is the caller's
     * own user-principal, or the caller is an owner/admin of the underlying
     * group. The controller is responsible for the admin flag; this method
     * only models the principal axis.
     */
    private function callerMayTargetPrincipal(int $callerUserId, bool $isAdmin, int $principalId): bool
    {
        if ($isAdmin) {
            return true;
        }
        return $this->principalResolver->isPrincipalOwner($callerUserId, $principalId);
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
        $config = $this->service->getConfiguration($id, $userId ?? 0, $isAdmin);
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
        if ($config->principal_id !== null && ($userId === null || !$this->principalResolver->isPrincipalOwner($userId, (int) $config->principal_id))) {
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

        $schema = $this->schemaValidator->getSchemaForDriver($config->driver_class);
        $existing = $this->service->decodeSettings($config->driver_class, $config->getRawOriginal('settings') ?? '');
        $merged = array_merge($existing, $body['settings']);
        $validationError = $this->schemaValidator->validateSettings($merged, $schema);
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
