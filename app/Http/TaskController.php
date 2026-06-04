<?php

declare(strict_types=1);

namespace Spora\Http;

use Carbon\Carbon;
use InvalidArgumentException;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles task listing, status updates, cancellation, and real-time SSE streaming.
 */
final class TaskController
{
    private const ERR_TASK_NOT_FOUND = 'Task not found.';

    private const ERR_INVALID_JSON = 'Request body must be valid JSON.';

    public function __construct(
        private readonly AuthService $authService,
        private readonly TaskServiceInterface $taskService,
    ) {}

    /**
     * GET /api/v1/tasks
     * Optional ?agent_id=X query param to scope results to a specific agent.
     * Optional ?page=X&per_page=X for pagination (default per_page=20, max=100).
     */
    public function index(Request $request): JsonResponse
    {
        $userId  = $this->authService->currentUserId();
        $agentId = $request->query->has('agent_id') ? (int) $request->query->get('agent_id') : null;
        $since = $request->query->has('since') ? $request->query->get('since') : null;

        $page = $request->query->has('page') ? max(1, (int) $request->query->get('page')) : null;
        $perPageRaw = $request->query->has('per_page') ? (int) $request->query->get('per_page') : null;
        $perPage = $perPageRaw !== null ? min(max(1, $perPageRaw), 100) : null;

        // Compute server_time before querying to avoid gaps on next poll
        $serverTime = Carbon::now()->toIso8601String();

        // Agent ownership validation is done inside the service
        $result = $this->taskService->getTasksForUser($userId, $agentId, $since, $page, $perPage);

        // When paginated, result is ['tasks' => [...], 'meta' => [...]] (not a list)
        // When not paginated, result is a flat array (list)
        if (!array_is_list($result) && array_key_exists('tasks', $result)) {
            return new JsonResponse([
                'data' => [
                    'tasks'       => $result['tasks'],
                    'server_time' => $serverTime,
                    'meta'        => $result['meta'],
                ],
            ]);
        }

        return new JsonResponse([
            'data' => [
                'tasks'       => $result,
                'server_time' => $serverTime,
            ],
        ]);
    }

    /**
     * POST /api/v1/tasks
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => self::ERR_INVALID_JSON]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $prompt = trim((string) ($body['prompt'] ?? ''));
        $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : null;
        $maxSteps = isset($body['max_steps']) ? (int) $body['max_steps'] : null;
        $parentTaskId = isset($body['parent_task_id']) ? (int) $body['parent_task_id'] : null;

        $result = null;
        if ($prompt === '') {
            $result = new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'prompt is required.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } elseif ($agentId === null) {
            $result = new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'agent_id is required.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } else {
            try {
                $task = $this->taskService->startTask($userId, $agentId, $prompt, $maxSteps, $parentTaskId);
                $result = new JsonResponse(
                    ['data' => ['task' => $task]],
                    Response::HTTP_CREATED,
                );
            } catch (InvalidArgumentException $e) {
                $result = new JsonResponse(
                    ['error' => ['code' => 'NOT_FOUND', 'message' => $e->getMessage()]],
                    Response::HTTP_NOT_FOUND,
                );
            }
        }

        return $result;
    }

    /**
     * GET /api/v1/tasks/{taskId}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
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
                ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
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
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => self::ERR_INVALID_JSON]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Accept either a modern batch payload { "approvals": [...] }
        // or the legacy single-tool format { "provider_call_id": "...", "arguments": {...} }
        $result = null;
        $approvedBatch = [];
        if (isset($body['approvals']) && is_array($body['approvals'])) {
            $approvedBatch = $body['approvals'];
        } else {
            $providerId = trim((string) ($body['provider_call_id'] ?? ''));
            if ($providerId === '') {
                $result = new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'provider_call_id is required.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            } else {
                $approvedBatch = [[
                    'provider_call_id' => $providerId,
                    'arguments'        => (array) ($body['arguments'] ?? []),
                ]];
            }
        }

        if ($result === null) {
            // Normalize arguments: ensure all approved argument objects are arrays,
            // not stdClass (which can happen when request body is JSON-decoded).
            foreach ($approvedBatch as &$item) {
                if (!isset($item['provider_call_id'])) {
                    $result = new JsonResponse(
                        ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'provider_call_id is required in all approvals.']],
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                    );
                    break;
                }
                if (isset($item['arguments']) && is_object($item['arguments'])) {
                    $item['arguments'] = (array) $item['arguments'];
                }
            }
            unset($item);
        }

        if ($result === null) {
            try {
                $task = $this->taskService->approveTask($taskId, $userId, $approvedBatch);
                $result = new JsonResponse(['data' => ['task' => $task]]);
            } catch (InvalidArgumentException $e) {
                $result = $e->getMessage() === self::ERR_TASK_NOT_FOUND
                    ? new JsonResponse(
                        ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
                        Response::HTTP_NOT_FOUND,
                    )
                    : new JsonResponse(
                        ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
                        Response::HTTP_CONFLICT,
                    );
            }
        }

        return $result;
    }

    /**
     * POST /api/v1/tasks/{taskId}/reject
     */
    public function reject(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => self::ERR_INVALID_JSON]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $reason = trim((string) ($body['reason'] ?? 'No reason provided.'));

        $result = null;
        try {
            $task = $this->taskService->rejectTask($taskId, $userId, $reason);
            $result = new JsonResponse(['data' => ['task' => $task]]);
        } catch (InvalidArgumentException $e) {
            $result = $e->getMessage() === self::ERR_TASK_NOT_FOUND
                ? new JsonResponse(
                    ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
                    Response::HTTP_NOT_FOUND,
                )
                : new JsonResponse(
                    ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
                    Response::HTTP_CONFLICT,
                );
        }

        return $result;
    }

    /**
     * DELETE /api/v1/tasks/{taskId}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        if (!$this->taskService->deleteTask($taskId, $userId)) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
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
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $task = $this->taskService->retryTask($taskId, $userId);
        } catch (InvalidArgumentException $e) {
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
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        $body = json_decode($request->getContent(), true) ?? [];

        $prompt = $body['prompt'] ?? null;
        $additionalSteps = null;

        $result = null;
        if (!is_string($prompt) || trim($prompt) === '') {
            $result = new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'prompt is required and must be a non-empty string.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } elseif (isset($body['additional_steps'])
            && (!is_int($body['additional_steps']) || $body['additional_steps'] < 1 || $body['additional_steps'] > 100)
        ) {
            $result = new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'additional_steps must be an integer between 1 and 100.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } else {
            if (isset($body['additional_steps'])) {
                $additionalSteps = $body['additional_steps'];
            }
            try {
                $task = $this->taskService->continueTask($taskId, $userId, $prompt, $additionalSteps);
                $result = new JsonResponse(
                    ['data' => ['task' => $task]],
                    Response::HTTP_OK,
                );
            } catch (InvalidArgumentException $e) {
                $result = $e->getMessage() === self::ERR_TASK_NOT_FOUND
                    ? new JsonResponse(
                        ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
                        Response::HTTP_NOT_FOUND,
                    )
                    : new JsonResponse(
                        ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
                        Response::HTTP_CONFLICT,
                    );
            }
        }

        return $result;
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
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $this->taskService->cancelRetryChain($taskId, $userId);
        } catch (InvalidArgumentException $e) {
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

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
