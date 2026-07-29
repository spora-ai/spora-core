<?php

declare(strict_types=1);

namespace Spora\Http;

use Carbon\Carbon;
use InvalidArgumentException;
use JsonException;
use OpenApi\Attributes as OA;
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
        private readonly ContinueTaskDispatcher $continuationDispatcher,
        private readonly DecisionsRequestValidator $decisionsValidator,
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
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['decisions'],
            properties: [
                new OA\Property(
                    property: 'decisions',
                    type: 'array',
                    minItems: 1,
                    items: new OA\Items(
                        type: 'object',
                        required: ['provider_call_id', 'decision'],
                        properties: [
                            new OA\Property(property: 'provider_call_id', type: 'string'),
                            new OA\Property(property: 'decision', type: 'string', enum: ['approve', 'reject']),
                            new OA\Property(property: 'arguments', type: 'object', description: 'Required when decision is approve.', additionalProperties: true),
                            new OA\Property(property: 'reason', type: 'string', description: 'Optional when decision is reject.'),
                        ],
                    ),
                ),
            ],
        ),
    )]
    public function approve(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->invalidJsonResponse();
        }

        $decisions = $this->decisionsValidator->parseAndValidate($body, $taskId, $userId);
        if ($decisions instanceof JsonResponse) {
            return $decisions;
        }

        return $this->approveTaskOrError($taskId, $userId, $decisions);
    }

    /**
     * @param list<array<string, mixed>> $decisions
     */
    private function approveTaskOrError(int $taskId, int $userId, array $decisions): JsonResponse
    {
        try {
            $task = $this->taskService->approveTask($taskId, $userId, $decisions);
            return new JsonResponse(['data' => ['task' => $task]]);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === self::ERR_TASK_NOT_FOUND || $e->getMessage() === 'Task is not pending approval.') {
                return $this->errorForException($e);
            }
            return $this->validationErrorResponse($e);
        }
    }

    private function validationErrorResponse(InvalidArgumentException $e): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function invalidJsonResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'INVALID_JSON', 'message' => self::ERR_INVALID_JSON]],
            Response::HTTP_BAD_REQUEST,
        );
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

        return $this->continuationDispatcher->handleContinue($taskId, $userId, $body);
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
