<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Services\LLMConfigServiceInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages user preferences, currently limited to preferred LLM configuration.
 *
 * Wire format cutover (migration 0067): the caller's user_id is no longer
 * the storage key — preferences hang off {@see \Spora\Models\Principal}.
 * For backwards compatibility the URL path stays the same
 * (`/api/v1/user-preferences/llm`); the principal id is supplied via
 * `?principal_id=…` for shared-group lookups. When omitted, the
 * endpoint resolves to the caller's own user-principal.
 */
final class UserPreferenceController
{
    use JsonControllerHelpers;

    private readonly PrincipalResolver $resolver;
    private readonly PrincipalService $principalService;

    public function __construct(
        private readonly AuthService $authService,
        private readonly LLMConfigServiceInterface $llmConfigService,
        ?PrincipalResolver $resolver = null,
        ?PrincipalService $principalService = null,
    ) {
        $this->resolver = $resolver ?? new PrincipalResolver();
        $this->principalService = $principalService ?? new PrincipalService($this->resolver);
    }

    /**
     * GET /api/v1/user-preferences/llm?principal_id=…
     *
     * Returns the preferred LLM configuration for the requested
     * principal. Defaults to the caller's own user-principal when the
     * query param is missing.
     */
    public function show(Request $request): JsonResponse
    {
        $resolved = $this->resolveUserAndPrincipal($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        $principalId = $resolved;

        $config = $this->llmConfigService->getPrincipalPreferredConfig($principalId);

        if ($config === null) {
            return new JsonResponse(['data' => ['config' => null]]);
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($config)]]);
    }

    /**
     * PUT /api/v1/user-preferences/llm?principal_id=…
     *
     * Sets the preferred LLM configuration for the requested principal.
     * Body: { "config_id": int|null }
     */
    public function update(Request $request): JsonResponse
    {
        $resolved = $this->resolveUserAndPrincipal($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        $principalId = $resolved;

        $body = $this->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->applyConfigChange($principalId, $body['config_id'] ?? null);
    }

    private function applyConfigChange(int $principalId, mixed $configId): JsonResponse
    {
        if ($configId === null) {
            return $this->clearPreference($principalId);
        }
        return $this->setPreference($principalId, (int) $this->authService->currentUserId(), $configId);
    }

    /**
     * @return int|JsonResponse
     */
    private function resolveUserAndPrincipal(Request $request): int|JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $principalId = $this->resolvePrincipalId($request, (int) $userId);
        if ($principalId === null) {
            return $this->forbidden('FORBIDDEN', 'Caller does not control the requested principal.');
        }

        return $principalId;
    }

    private function clearPreference(int $principalId): JsonResponse
    {
        $this->llmConfigService->unsetPrincipalPreferredConfig($principalId);

        return new JsonResponse(['data' => ['config' => null]]);
    }

    private function setPreference(int $principalId, int $callerUserId, mixed $configId): JsonResponse
    {
        if (!is_int($configId)) {
            return $this->error('VALIDATION_ERROR', 'config_id must be an integer or null.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $success = $this->llmConfigService->setPrincipalPreferredConfig($principalId, $configId, $callerUserId);
        if (!$success) {
            return $this->error(
                'VALIDATION_ERROR',
                'Configuration not found or does not belong to the requested principal.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->buildPreferenceResponse($principalId);
    }

    private function buildPreferenceResponse(int $principalId): JsonResponse
    {
        $config = $this->llmConfigService->getPrincipalPreferredConfig($principalId);

        return new JsonResponse(['data' => ['config' => $config !== null ? $this->llmConfigService->configResource($config) : null]]);
    }

    /**
     * Resolve the target principal. Falls back to the caller's
     * user-principal when no `principal_id` query param is supplied.
     * Returns null when the caller doesn't control the requested
     * principal — the controller then responds with 403.
     */
    private function resolvePrincipalId(Request $request, int $userId): ?int
    {
        $principalId = $this->requestedPrincipalId($request);
        if ($principalId === null) {
            return $this->defaultPrincipalId($userId);
        }

        if (!$this->callerMayActOnPrincipal($userId, $principalId)) {
            return null;
        }

        return $principalId;
    }

    private function requestedPrincipalId(Request $request): ?int
    {
        $raw = $request->query->get('principal_id');
        if ($raw === null || $raw === '') {
            return null;
        }
        $principalId = (int) $raw;
        return $principalId > 0 ? $principalId : null;
    }

    private function defaultPrincipalId(int $userId): int
    {
        return (int) $this->principalService->ensureUserPrincipal($userId)->id;
    }

    private function callerMayActOnPrincipal(int $userId, int $principalId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }
        return $this->principalService->callerControlsPrincipal($userId, $principalId);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBodyOrFail(Request $request): array|JsonResponse
    {
        try {
            return $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
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

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
