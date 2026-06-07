<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Services\AgentServiceInterface;
use Spora\Services\ToolConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent-scoped tool and LLM configuration override endpoints.
 *
 * Routes:
 *   GET    /api/v1/agents/{id}/tools/{toolId}/override
 *   PUT    /api/v1/agents/{id}/tools/{toolId}/override
 *   DELETE /api/v1/agents/{id}/tools/{toolId}/override
 *   GET    /api/v1/agents/{id}/tools/{toolId}/operations/{operation}
 *   PATCH  /api/v1/agents/{id}/tools/{toolId}/operations/{operation}
 */
final class AgentOverrideController
{
    use AgentControllerHelpers;

    private const MSG_INVALID_JSON = 'Request body must be valid JSON.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
        private readonly ToolConfigService $toolConfigService,
    ) {}

    /**
     * GET /api/v1/agents/{id}/tools/{toolId}/override
     */
    public function getOverride(Request $request): JsonResponse
    {
        $userId   = $this->authService->currentUserId();
        $agentId  = (int) $request->attributes->get('id', 0);
        $toolId   = (string) $request->attributes->get('toolId', '');
        $rawOnly  = $request->query->get('raw') === 'true';

        $toolClass = $toolId === 'llm_configuration'
            ? 'llm_configuration'
            : $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return $this->notFound();
        }

        $settings = $this->agentService->getOverride($agentId, $userId, $toolClass, $rawOnly);

        return new JsonResponse(['data' => ['settings' => $settings]]);
    }

    /**
     * PUT /api/v1/agents/{id}/tools/{toolId}/override
     */
    public function putOverride(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }

        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;

        $masked = $this->agentService->putOverride($agentId, $userId, $toolClass, $settings);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    /**
     * DELETE /api/v1/agents/{id}/tools/{toolId}/override
     */
    public function deleteOverride(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->notFound();
        }

        $this->agentService->deleteOverride($agentId, $userId, $toolClass);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/{toolId}/operations/{operation}
     */
    public function getOperationOverride(Request $request): JsonResponse
    {
        $userId     = $this->authService->currentUserId();
        $agentId    = (int) $request->attributes->get('id', 0);
        $toolClass  = $this->resolveToolClassFromRequest($request);
        $operation  = (string) $request->attributes->get('operation', '');

        if ($toolClass === null || $operation === '') {
            return $this->notFound();
        }

        $result = $this->agentService->getOperationOverride($agentId, $userId, $toolClass, $operation);

        return new JsonResponse(['data' => $result]);
    }

    /**
     * PATCH /api/v1/agents/{id}/tools/{toolId}/operations/{operation}
     */
    public function patchOperationOverride(Request $request): JsonResponse
    {
        $userId     = $this->authService->currentUserId();
        $agentId    = (int) $request->attributes->get('id', 0);
        $toolClass  = $this->resolveToolClassFromRequest($request);
        $operation  = (string) $request->attributes->get('operation', '');

        if ($toolClass === null || $operation === '') {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', self::MSG_INVALID_JSON, Response::HTTP_BAD_REQUEST);
        }

        $result = $this->agentService->patchOperationOverride($agentId, $userId, $toolClass, $operation, $body);

        return new JsonResponse(['data' => $result]);
    }
}
