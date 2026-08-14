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
     * Re-runs the failed task in place: same task_id and URL, full conversation
     * history preserved as LLM context (the model sees the prior failed turn
     * and can retry or take an alternative path on transient errors). The
     * task's status, step_count, and error fields are reset; max_steps is kept.
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
            Response::HTTP_OK,
        );
    }

    /**
     * POST /api/v1/tasks/{taskId}/continue
     *
     * Continues a completed, failed, aborted, or running task with a new
     * prompt. Running sources auto-abort; aborted sources resume; the
     * other two follow the existing continue flow. Appends the new prompt
     * (and, on the RUNNING branch, an abort-marker system row) to the
     * existing task's history.
     */
    public function continue(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        $body = json_decode($request->getContent(), true) ?? [];

        return $this->continuationDispatcher->handleContinue($taskId, $userId, $body);
    }

    /**
     * POST /api/v1/tasks/{taskId}/abort
     *
     * Halts the running agent loop by flipping the task status to
     * `ABORTED`. The actual LLM/tool loop observes the flip at the next
     * tool-batch boundary via {@see Spora\Agents\TickPhaseRunner}
     * (see the abort-bail comments there) — this endpoint does NOT
     * block on tool completion. Accepted source states: `RUNNING`,
     * `AWAITING_SUB_AGENTS`. The task is then in the quiescent
     * `ABORTED` status and the user can send a new instruction via
     * POST /continue to resume.
     *
     * Responses:
     *   - 200: task was `RUNNING` (or `AWAITING_SUB_AGENTS`) and is now
     *          `ABORTED`. Body is the full task resource with the new
     *          `status` and `data.aborted_at` field.
     *   - 409: task is in a state that doesn't allow aborting (terminal
     *          or already-paused via approve/reject).
     *   - 404: task not found / not owned.
     */
    #[OA\Post(
        path: '/api/v1/tasks/{taskId}/abort',
        tags: ['Tasks'],
        summary: 'Abort Task',
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
                description: 'JSON envelope: `{data: {task: ...}}` with status `ABORTED`.',
            ),
            new OA\Response(
                response: 409,
                description: 'INVALID_STATE — task is terminal or already-paused.',
            ),
            new OA\Response(
                response: 404,
                description: 'NOT_FOUND — task is not owned by the calling user.',
            ),
        ],
    )]
    public function abort(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $task = $this->taskService->abortTask($taskId, $userId);
            return new JsonResponse(['data' => ['task' => $task]]);
        } catch (InvalidArgumentException $e) {
            return $this->errorForException($e);
        }
    }

    /**
     * POST /api/v1/tasks/{taskId}/abort-sub-agent
     *
     * Aborts a sub-agent child task and cascades the abort up the
     * parent chain — every `AWAITING_SUB_AGENTS` ancestor is also
     * flipped to `ABORTED`. The caller must own the child; ancestors
     * are system-aborted and informed via Mercure. Same error contract
     * as the plain /abort endpoint: 404 when the task is not owned,
     * 409 when the source state doesn't allow aborting.
     */
    #[OA\Post(
        path: '/api/v1/tasks/{taskId}/abort-sub-agent',
        tags: ['Tasks'],
        summary: 'Abort sub-agent and cascade-up',
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
                description: 'JSON envelope: `{data: {task: ...}}` with status `ABORTED`.',
            ),
            new OA\Response(
                response: 409,
                description: 'INVALID_STATE — task is terminal or already-paused.',
            ),
            new OA\Response(
                response: 404,
                description: 'NOT_FOUND — task is not owned by the calling user.',
            ),
        ],
    )]
    public function abortSubAgent(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            $task = $this->taskService->abortSubAgentAndCascade($taskId, $userId);
            return new JsonResponse(['data' => ['task' => $task]]);
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
