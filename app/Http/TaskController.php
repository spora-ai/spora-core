<?php

declare(strict_types=1);

namespace Spora\Http;

use Carbon\Carbon;
use InvalidArgumentException;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Services\MediaArchive\MediaCapabilityMismatchException;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
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
        private readonly TaskMediaCapabilityService $mediaCapability,
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

        $prompt       = trim((string) ($body['prompt'] ?? ''));
        $agentId      = isset($body['agent_id']) ? (int) $body['agent_id'] : null;
        $maxSteps     = isset($body['max_steps']) ? (int) $body['max_steps'] : null;
        $parentTaskId = isset($body['parent_task_id']) ? (int) $body['parent_task_id'] : null;
        $mediaIds     = $this->mediaCapability->parseMediaIds($body['media_ids'] ?? null);

        $validation = $this->validateStartTaskFields($prompt, $agentId);
        if ($validation !== null) {
            return $validation;
        }

        return $this->startTaskWithCapability($userId, $agentId, $prompt, $maxSteps, $parentTaskId, $mediaIds);
    }

    /**
     * @param list<string> $mediaIds
     */
    private function startTaskWithCapability(
        int $userId,
        int $agentId,
        string $prompt,
        ?int $maxSteps,
        ?int $parentTaskId,
        array $mediaIds,
    ): JsonResponse {
        try {
            $this->mediaCapability->ensureMediaCapabilityCompatible($agentId, $mediaIds);
            $task = $this->taskService->startTask($userId, $agentId, $prompt, $maxSteps, $parentTaskId, $mediaIds);
            return new JsonResponse(['data' => ['task' => $task]], Response::HTTP_CREATED);
        } catch (MediaCapabilityMismatchException $e) {
            return new JsonResponse(
                ['error' => ['code' => 'MEDIA_CAPABILITY_MISMATCH', 'message' => $e->getMessage()]],
                Response::HTTP_BAD_REQUEST,
            );
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => $e->getMessage()]],
                Response::HTTP_NOT_FOUND,
            );
        }
    }

    private function validateStartTaskFields(string $prompt, ?int $agentId): ?JsonResponse
    {
        if ($prompt === '') {
            return new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'prompt is required.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        if ($agentId === null || $agentId <= 0) {
            $message = $agentId === null
                ? 'agent_id is required.'
                : 'agent_id must be a positive integer.';
            return new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => $message]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        return null;
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
            return $this->invalidJsonResponse();
        }

        $batch = $this->parseAndValidateApprovalBatch($body);
        if ($batch instanceof JsonResponse) {
            return $batch;
        }

        return $this->approveTaskOrError($taskId, $userId, $batch);
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function approveTaskOrError(int $taskId, int $userId, array $batch): JsonResponse
    {
        try {
            $task = $this->taskService->approveTask($taskId, $userId, $batch);
            return new JsonResponse(['data' => ['task' => $task]]);
        } catch (InvalidArgumentException $e) {
            return $this->errorForException($e);
        }
    }

    private function invalidJsonResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'INVALID_JSON', 'message' => self::ERR_INVALID_JSON]],
            Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * Accept either a modern batch payload { "approvals": [...] }
     * or the legacy single-tool format { "provider_call_id": "...", "arguments": {...} },
     * then validate each entry has a provider_call_id and normalize object
     * arguments to arrays (stdClass can leak in from JSON-decoded request bodies).
     *
     * @return list<array<string, mixed>>|JsonResponse
     */
    private function parseAndValidateApprovalBatch(array $body): array|JsonResponse
    {
        if (isset($body['approvals']) && is_array($body['approvals'])) {
            $batch = $body['approvals'];
        } else {
            $providerId = trim((string) ($body['provider_call_id'] ?? ''));
            if ($providerId === '') {
                return new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'provider_call_id is required.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $batch = [[
                'provider_call_id' => $providerId,
                'arguments'        => (array) ($body['arguments'] ?? []),
            ]];
        }

        foreach ($batch as $item) {
            if (!isset($item['provider_call_id'])) {
                return new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'provider_call_id is required in all approvals.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }
        foreach ($batch as &$item) {
            if (isset($item['arguments']) && is_object($item['arguments'])) {
                $item['arguments'] = (array) $item['arguments'];
            }
        }
        unset($item);

        return $batch;
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

        try {
            $task = $this->taskService->rejectTask($taskId, $userId, $reason);
            return new JsonResponse(['data' => ['task' => $task]]);
        } catch (InvalidArgumentException $e) {
            return $this->errorForException($e);
        }
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
            return $this->errorForException($e);
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

        $validation = $this->validateContinueBody($body);
        if ($validation['result'] !== null) {
            return $validation['result'];
        }

        return $this->dispatchContinue(
            $taskId,
            $userId,
            $validation['prompt'],
            $validation['additionalSteps'],
            $validation['mediaIds'],
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{result: ?JsonResponse, prompt: ?string, additionalSteps: ?int, mediaIds: list<string>}
     */
    private function validateContinueBody(array $body): array
    {
        $prompt = $body['prompt'] ?? null;
        if (!is_string($prompt) || trim($prompt) === '') {
            return [
                'result' => new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'prompt is required and must be a non-empty string.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ),
                'prompt' => null,
                'additionalSteps' => null,
                'mediaIds' => [],
            ];
        }

        if (isset($body['additional_steps'])
            && (!is_int($body['additional_steps']) || $body['additional_steps'] < 1 || $body['additional_steps'] > 100)
        ) {
            return [
                'result' => new JsonResponse(
                    ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'additional_steps must be an integer between 1 and 100.']],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ),
                'prompt' => null,
                'additionalSteps' => null,
                'mediaIds' => [],
            ];
        }

        return [
            'result' => null,
            'prompt' => $prompt,
            'additionalSteps' => isset($body['additional_steps']) ? $body['additional_steps'] : null,
            'mediaIds' => $this->mediaCapability->parseMediaIds($body['media_ids'] ?? null),
        ];
    }

    /**
     * @param list<string> $mediaIds
     */
    private function dispatchContinue(
        int $taskId,
        int $userId,
        string $prompt,
        ?int $additionalSteps,
        array $mediaIds,
    ): JsonResponse {
        $existing = $this->taskService->getTask($taskId, $userId);
        if ($existing === null) {
            return $this->notFoundResponse();
        }

        try {
            $this->mediaCapability->ensureMediaCapabilityCompatible($existing['agent_id'], $mediaIds);
        } catch (MediaCapabilityMismatchException $e) {
            return new JsonResponse(
                ['error' => ['code' => 'MEDIA_CAPABILITY_MISMATCH', 'message' => $e->getMessage()]],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $task = $this->taskService->continueTask($taskId, $userId, $prompt, $additionalSteps, $mediaIds);
            return new JsonResponse(['data' => ['task' => $task]], Response::HTTP_OK);
        } catch (InvalidArgumentException $e) {
            return $this->errorForException($e);
        }
    }

    /**
     * Map a service-layer {@see InvalidArgumentException} to the JSON
     * error response the API contract uses for "task not found" and
     * "invalid state" failures.
     */
    private function errorForException(InvalidArgumentException $e): JsonResponse
    {
        if ($e->getMessage() === self::ERR_TASK_NOT_FOUND) {
            return $this->notFoundResponse();
        }
        return new JsonResponse(
            ['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]],
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * Canonical 404 JSON response for "task not found", shared by every
     * endpoint that resolves the task through the service layer.
     */
    private function notFoundResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
            Response::HTTP_NOT_FOUND,
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
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $this->taskService->cancelRetryChain($taskId, $userId);
        } catch (InvalidArgumentException $e) {
            return $this->errorForException($e);
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
