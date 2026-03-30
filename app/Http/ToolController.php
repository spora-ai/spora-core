<?php

declare(strict_types=1);

namespace Spora\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TODO: Implement tool configuration endpoints.
 */
final class ToolController
{
    public function index(Request $request, array $vars = []): JsonResponse
    {
        // TODO: List all registered tools with their settings schema
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function getSettings(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Return effective settings for a tool (masked passwords) via ToolConfigService
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function putSettings(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Update global tool settings via ToolConfigService
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }
}
