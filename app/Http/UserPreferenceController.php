<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Services\LLMConfigServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages user preferences, currently limited to preferred LLM configuration.
 */
final class UserPreferenceController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly LLMConfigServiceInterface $llmConfigService,
    ) {}

    /**
     * GET /api/v1/user-preferences/llm
     *
     * Returns the authenticated user's preferred LLM configuration.
     */
    public function show(): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $config = $this->llmConfigService->getUserPreferredConfig($userId);

        if ($config === null) {
            return new JsonResponse(['data' => ['config' => null]]);
        }

        return new JsonResponse(['data' => ['config' => $this->llmConfigService->configResource($config)]]);
    }

    /**
     * PUT /api/v1/user-preferences/llm
     *
     * Sets the authenticated user's preferred LLM configuration.
     * Body: { "config_id": int|null }
     */
    public function update(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $body = $this->decodeBodyOrFail($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $configId = $body['config_id'] ?? null;

        // Null means clear the preference
        if ($configId === null) {
            return $this->clearPreference($userId);
        }

        return $this->setPreference($userId, $configId);
    }

    private function clearPreference(?int $userId): JsonResponse
    {
        $this->llmConfigService->unsetUserPreferredConfig($userId);

        return new JsonResponse(['data' => ['config' => null]]);
    }

    private function setPreference(?int $userId, mixed $configId): JsonResponse
    {
        if (!is_int($configId)) {
            return $this->error('VALIDATION_ERROR', 'config_id must be an integer or null.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $success = $this->llmConfigService->setUserPreferredConfig($userId, $configId);
        if (!$success) {
            return $this->error(
                'VALIDATION_ERROR',
                'Configuration not found or does not belong to you.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->buildPreferenceResponse($userId);
    }

    private function buildPreferenceResponse(?int $userId): JsonResponse
    {
        $config = $this->llmConfigService->getUserPreferredConfig($userId);

        return new JsonResponse(['data' => ['config' => $config !== null ? $this->llmConfigService->configResource($config) : null]]);
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
