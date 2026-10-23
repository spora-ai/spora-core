<?php

declare(strict_types=1);

namespace Spora\Http;

use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/tasks/{taskId}/compact
 *
 * Replaces everything but the last few rows of the task's history with a
 * summary for the model. The summarised rows stay in the history — the
 * operator can still read them — so this changes what the model is sent, not
 * what the transcript holds.
 *
 * Lives beside {@see TaskController} rather than inside it: that class is
 * already at the SonarCloud S1448 method-count ceiling.
 */
final class TaskCompactController
{
    private const ERR_TASK_NOT_FOUND = 'Task not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly TaskServiceInterface $taskService,
    ) {}

    #[OA\Post(
        path: '/api/v1/tasks/{taskId}/compact',
        tags: ['Tasks'],
        summary: 'Compact Task History',
        parameters: [
            new OA\Parameter(
                name: 'taskId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'JSON envelope: `{data: {task: ..., compacted: bool}}`. `compacted` is false when the history was too short to summarise.',
            ),
            new OA\Response(
                response: 409,
                description: 'INVALID_STATE — the task is not in a state that can be compacted.',
            ),
            new OA\Response(
                response: 404,
                description: 'NOT_FOUND — task is not owned by the calling user.',
            ),
        ],
    )]
    public function compact(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $result = $this->taskService->compactTask($taskId, $userId);
        } catch (InvalidArgumentException $e) {
            return $this->errorForException($e);
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * Map a service-layer {@see InvalidArgumentException} to the JSON
     * error response the API contract uses for "task not found" and
     * "invalid state" failures.
     */
    private function errorForException(InvalidArgumentException $e): JsonResponse
    {
        if ($e->getMessage() === self::ERR_TASK_NOT_FOUND) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(
            ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
            Response::HTTP_CONFLICT,
        );
    }
}
