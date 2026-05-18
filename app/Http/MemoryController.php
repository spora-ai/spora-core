<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Services\MemoryServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MemoryController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly MemoryServiceInterface $memoryService,
    ) {}

    /**
     * GET /api/v1/memories
     */
    public function index(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $memories = $this->memoryService->listGlobalMemories($userId);

        return new JsonResponse(['data' => ['memories' => $memories]]);
    }

    /**
     * POST /api/v1/memories
     */
    public function store(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

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
            $result = $this->memoryService->createGlobalMemory($userId, $body);
            return new JsonResponse(['data' => $result], Response::HTTP_CREATED);
        } catch (RuntimeException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * GET /api/v1/memories/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $memoryId = (int) $request->attributes->get('id', 0);

        $result = $this->memoryService->getGlobalMemory($memoryId, $userId);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * PUT /api/v1/memories/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $memoryId = (int) $request->attributes->get('id', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->memoryService->updateGlobalMemory($memoryId, $userId, $body);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * DELETE /api/v1/memories/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $memoryId = (int) $request->attributes->get('id', 0);

        $deleted = $this->memoryService->deleteGlobalMemory($memoryId, $userId);

        if (! $deleted) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * PATCH /api/v1/memories/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $order = $body['order'] ?? [];
        if (! is_array($order)) {
            return $this->error('VALIDATION_ERROR', 'order must be an array of memory IDs.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->memoryService->reorderGlobalMemories($userId, array_values($order));

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
