<?php

declare(strict_types=1);

namespace Spora\Http;

use Carbon\Carbon;
use InvalidArgumentException;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly TaskServiceInterface $taskService,
    ) {}

    /**
     * GET /api/v1/tasks
     * Optional ?agent_id=X query param to scope results to a specific agent.
     */
    public function index(Request $request): JsonResponse
    {
        $userId  = AuthGuard::requireAuth($this->authService);
        $agentId = $request->query->has('agent_id') ? (int) $request->query->get('agent_id') : null;
        $since = $request->query->has('since') ? $request->query->get('since') : null;

        // Compute server_time before querying to avoid gaps on next poll
        $serverTime = Carbon::now()->toIso8601String();

        // Agent ownership validation is done inside the service
        $tasks = $this->taskService->getTasksForUser($userId, $agentId, $since);

        return new JsonResponse([
            'data' => [
                'tasks'       => $tasks,
                'server_time' => $serverTime,
            ],
        ]);
    }

    /**
     * POST /api/v1/tasks
     */
    public function store(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $prompt = trim((string) ($body['prompt'] ?? ''));

        if ($prompt === '') {
            return new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'prompt is required.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : null;

        if ($agentId === null) {
            return new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'agent_id is required.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $maxSteps = isset($body['max_steps']) ? (int) $body['max_steps'] : null;
        $parentTaskId = isset($body['parent_task_id']) ? (int) $body['parent_task_id'] : null;

        try {
            $task = $this->taskService->startTask($userId, $agentId, $prompt, $maxSteps, $parentTaskId);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => $e->getMessage()]],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(
            ['data' => ['task' => $task]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/tasks/{taskId}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $taskId = (int) $request->attributes->get('taskId', 0);

        $sinceSequence = null;
        if ($request->query->has('since_sequence')) {
            $sinceSequence = (int) $request->query->get('since_sequence');
            if ($sinceSequence < 0) {
                $sinceSequence = null;
            }
        }

        $task = $this->taskService->getTaskWithHistory($taskId, $userId, $sinceSequence);

        if ($task === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(['data' => ['task' => $task]]);
    }

    /**
     * POST /api/v1/tasks/{taskId}/approve
     */
    public function approve(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Accept either a modern batch payload { "approvals": [...] }
        // or the legacy single-tool format { "provider_call_id": "...", "arguments": {...} }
        if (isset($body['approvals']) && is_array($body['approvals'])) {
            $approvedBatch = $body['approvals'];
        } else {
            $providerId = trim((string) ($body['provider_call_id'] ?? ''));
            if ($providerId === '') {
                return new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'provider_call_id is required.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $approvedBatch = [[
                'provider_call_id' => $providerId,
                'arguments'        => (array) ($body['arguments'] ?? []),
            ]];
        }

        // Normalize arguments: ensure all approved argument objects are arrays,
        // not stdClass (which can happen when request body is JSON-decoded).
        foreach ($approvedBatch as &$item) {
            if (isset($item['arguments']) && is_object($item['arguments'])) {
                $item['arguments'] = (array) $item['arguments'];
            }
        }
        unset($item);

        try {
            $task = $this->taskService->approveTask($taskId, $userId, $approvedBatch);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'Task not found.') {
                return new JsonResponse(
                    ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                    Response::HTTP_NOT_FOUND,
                );
            }
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['data' => ['task' => $task]]);
    }

    /**
     * POST /api/v1/tasks/{taskId}/reject
     */
    public function reject(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $reason = trim((string) ($body['reason'] ?? 'No reason provided.'));

        try {
            $task = $this->taskService->rejectTask($taskId, $userId, $reason);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'Task not found.') {
                return new JsonResponse(
                    ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                    Response::HTTP_NOT_FOUND,
                );
            }
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['data' => ['task' => $task]]);
    }

    /**
     * DELETE /api/v1/tasks/{taskId}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $taskId = (int) $request->attributes->get('taskId', 0);

        if (!$this->taskService->deleteTask($taskId, $userId)) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * POST /api/v1/tasks/{taskId}/retry
     *
     * Creates a new task with the same agent_id and user_prompt as the failed task.
     * The new task is a fresh attempt — no parent_task_id link.
     */
    public function retry(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $task = $this->taskService->retryTask($taskId, $userId);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'Task not found.') {
                return new JsonResponse(
                    ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                    Response::HTTP_NOT_FOUND,
                );
            }
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(
            ['data' => ['task' => $task]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * POST /api/v1/tasks/{taskId}/continue
     *
     * Continues a completed or failed task with a new prompt.
     * Appends the new prompt to the existing task's history and resumes execution.
     */
    public function continue(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $taskId = (int) $request->attributes->get('taskId', 0);

        $body = json_decode($request->getContent(), true) ?? [];

        $prompt = $body['prompt'] ?? null;
        if (!is_string($prompt) || trim($prompt) === '') {
            return new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'prompt is required and must be a non-empty string.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $additionalSteps = null;
        if (isset($body['additional_steps'])) {
            if (!is_int($body['additional_steps']) || $body['additional_steps'] < 1 || $body['additional_steps'] > 100) {
                return new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'additional_steps must be an integer between 1 and 100.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $additionalSteps = $body['additional_steps'];
        }

        try {
            $task = $this->taskService->continueTask($taskId, $userId, $prompt, $additionalSteps);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'Task not found.') {
                return new JsonResponse(
                    ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                    Response::HTTP_NOT_FOUND,
                );
            }
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(
            ['data' => ['task' => $task]],
            Response::HTTP_OK,
        );
    }

    /**
     * DELETE /api/v1/tasks/{taskId}/retry-chain
     *
     * Cancels this task and ALL subsequent retry tasks in the same retry chain.
     * All retry tasks share the same retry_of_task_id (the root original task),
     * so a single WHERE clause cancels the entire chain.
     */
    public function cancelRetryChain(Request $request): Response
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $this->taskService->cancelRetryChain($taskId, $userId);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'Task not found.') {
                return new JsonResponse(
                    ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                    Response::HTTP_NOT_FOUND,
                );
            }
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
