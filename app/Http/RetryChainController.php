<?php

declare(strict_types=1);

namespace Spora\Http;

use InvalidArgumentException;
use Spora\Auth\AuthService;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owns DELETE /api/v1/tasks/{taskId}/retry-chain. Split out of
 * TaskController so the latter stays under the S1448 method-count
 * ceiling. Runtime contract is unchanged.
 */
final class RetryChainController
{
    private const ERR_TASK_NOT_FOUND = 'Task not found.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly TaskServiceInterface $taskService,
    ) {}

    /**
     * DELETE /api/v1/tasks/{taskId}/retry-chain
     *
     * Cancels this task and ALL subsequent retry tasks in the same retry chain.
     * All retry tasks share the same retry_of_task_id (the root original task),
     * so a single WHERE clause cancels the entire chain.
     */
    public function cancelRetryChain(Request $request): Response
    {
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $this->taskService->cancelRetryChain($taskId, $userId);
        } catch (InvalidArgumentException $e) {
            return $this->errorForException($e);
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

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
