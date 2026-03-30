<?php

declare(strict_types=1);

namespace Spora\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TODO: Implement task lifecycle endpoints (create, approve, reject).
 */
final class TaskController
{
    public function index(Request $request, array $vars = []): JsonResponse
    {
        // TODO: List tasks for the authenticated user
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function store(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Create and dispatch a new task
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function show(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Return a single task with tool calls and history
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function approve(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Approve a pending OutputTool call and resume the Orchestrator
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function reject(Request $request, array $vars = []): JsonResponse
    {
        // TODO: Reject a pending OutputTool call
        return new JsonResponse(['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => 'Not implemented.']], Response::HTTP_NOT_IMPLEMENTED);
    }
}
