<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Services\MemoryServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AgentMemoryController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly MemoryServiceInterface $memoryService,
    ) {}

    /**
     * GET /api/v1/agents/{agentId}/memories
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('agentId', 0);

        $memories = $this->memoryService->listAgentMemories($agentId, $userId);

        if ($memories === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['memories' => $memories]]);
    }

    /**
     * POST /api/v1/agents/{agentId}/memories
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('agentId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->memoryService->createAgentMemory($agentId, $userId, $body);
            return new JsonResponse(['data' => $result], Response::HTTP_CREATED);
        } catch (RuntimeException) {
            return $this->notFound();
        }
    }

    /**
     * GET /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (int) $request->attributes->get('memoryId', 0);

        $result = $this->memoryService->getAgentMemory($memoryId, $agentId, $userId);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * PUT /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (int) $request->attributes->get('memoryId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->memoryService->updateAgentMemory($memoryId, $agentId, $userId, $body);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * DELETE /api/v1/agents/{agentId}/memories/{memoryId}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('agentId', 0);
        $memoryId = (int) $request->attributes->get('memoryId', 0);

        $deleted = $this->memoryService->deleteAgentMemory($memoryId, $agentId, $userId);

        if (! $deleted) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * PATCH /api/v1/agents/{agentId}/memories/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('agentId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $order = $body['order'] ?? [];
        if (! is_array($order)) {
            return $this->error('VALIDATION_ERROR', 'order must be an array of memory IDs.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->memoryService->reorderAgentMemories($agentId, $userId, array_values($order));
        } catch (RuntimeException) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['success' => true]]);
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
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Memory not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
