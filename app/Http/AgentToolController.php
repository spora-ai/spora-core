<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Services\AgentServiceInterface;
use Spora\Services\ToolConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent-scoped tool enablement and status endpoints.
 *
 * Routes:
 *   POST   /api/v1/agents/{id}/tools/{toolId}/enable
 *   DELETE /api/v1/agents/{id}/tools/{toolId}/enable
 *   GET    /api/v1/agents/{id}/tools/status
 *   GET    /api/v1/agents/{id}/tools/{toolId}/status
 *   GET    /api/v1/agents/{id}/tools/operations
 */
final class AgentToolController
{
    use JsonControllerHelpers;
    use AgentControllerToolHelpers;
    private const MSG_AGENT_NOT_FOUND = 'Agent not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly AgentServiceInterface $agentService,
        private readonly ToolConfigService $toolConfigService,
    ) {}

    /**
     * POST /api/v1/agents/{id}/tools/{toolId}/enable
     */
    public function enableTool(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->error('VALIDATION_ERROR', 'toolClass is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->agentService->enableTool($agentId, $userId, $toolClass);

        if (array_key_exists('error', $result)) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        $isIdempotent = array_key_exists('is_idempotent', $result);
        $status = $isIdempotent ? Response::HTTP_OK : Response::HTTP_CREATED;
        if ($isIdempotent) {
            unset($result['is_idempotent']);
        }
        return new JsonResponse(['data' => $result], $status);
    }

    /**
     * DELETE /api/v1/agents/{id}/tools/{toolId}/enable
     */
    public function disableTool(Request $request): JsonResponse
    {
        $userId    = $this->authService->currentUserId();
        $agentId   = (int) $request->attributes->get('id', 0);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($toolClass === null) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        $this->agentService->disableTool($agentId, $userId, $toolClass);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/{toolId}/status
     */
    public function getToolStatus(Request $request): JsonResponse
    {
        $userId   = $this->authService->currentUserId();
        $agentId  = (int) $request->attributes->get('id', 0);
        $toolId   = (string) $request->attributes->get('toolId', '');
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        $status = $this->agentService->getToolStatus($agentId, $userId, $toolClass);

        if ($status === null) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => $status]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/status
     */
    public function getToolsStatus(Request $request): JsonResponse
    {
        $userId  = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $statuses = $this->agentService->getAllToolsStatus($agentId, $userId);

        if ($statuses === null) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['statuses' => $statuses]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/operations
     */
    public function getToolsOperations(Request $request): JsonResponse
    {
        $userId  = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $operations = $this->agentService->getToolsOperations($agentId, $userId);

        if ($operations === null) {
            return $this->notFound("AGENT_NOT_FOUND", self::MSG_AGENT_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['operations' => $operations]]);
    }
}
