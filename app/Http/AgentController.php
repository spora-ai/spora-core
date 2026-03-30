<?php

declare(strict_types=1);

namespace Spora\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TODO: Implement agent endpoints.
 */
final class AgentController
{
    public function show(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Return the authenticated user's agent
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function update(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Update the authenticated user's agent
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function enableTool(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Enable a tool for the agent (POST /api/v1/agent/tools/{toolClass}/enable)
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function patchTool(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Update auto_approve override for a tool (PATCH /api/v1/agent/tools/{toolClass})
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function disableTool(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Disable a tool for the agent (DELETE /api/v1/agent/tools/{toolClass}/enable)
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function getOverride(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Get per-agent credential override for a tool
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function putOverride(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Set per-agent credential override for a tool
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function deleteOverride(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Delete per-agent credential override for a tool
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }
}
