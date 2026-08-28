<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;
use OpenApi\Attributes as OA;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\TaskLifecyclePolicy;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Auth\AuthService;
use Spora\Http\Exceptions\TooManyRequestsException;
use Spora\Models\Task;
use Spora\Services\DbRateLimiter;
use Spora\Services\MediaArchive\MediaCapabilityMismatchException;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Handles task listing, status updates, cancellation, and real-time SSE streaming.
 */
final class TaskController
{
    private const ERR_TASK_NOT_FOUND = 'Task not found.';

    private const ERR_INVALID_JSON = 'Request body must be valid JSON.';

    /** Lease owner prefix the /tick controller writes to tasks.lease_owner. */
    private const LEASE_OWNER_PREFIX = 'user:';

    public function __construct(
        private readonly AuthService $authService,
        private readonly TaskServiceInterface $taskService,
        private readonly TaskMediaCapabilityService $mediaCapability,
        private readonly ContinueTaskDispatcher $continuationDispatcher,
        private readonly DecisionsRequestValidator $decisionsValidator,
        private readonly WorkerRuntimeMode $workerRuntimeMode,
        private readonly DbRateLimiter $rateLimiter,
        private readonly MercurePublisherInterface $mercure,
        private readonly OrchestratorInterface $orchestrator,
        private readonly int $tickLeaseSeconds,
    ) {}

    /**
     * GET /api/v1/tasks
     * Optional ?agent_id=X query param to scope results to a specific agent.
     * Optional ?status=X to filter by task status (e.g. RUNNING, FAILED).
     * Optional ?page=X&per_page=X for pagination (default per_page=20, max=100).
     */
    public function index(Request $request): JsonResponse
    {
        $userId  = $this->authService->currentUserId();
        $agentId = $request->query->has('agent_id') ? (int) $request->query->get('agent_id') : null;
        $status = $request->query->has('status') ? (string) $request->query->get('status') : null;
        $since = $request->query->has('since') ? $request->query->get('since') : null;

        $page = $request->query->has('page') ? max(1, (int) $request->query->get('page')) : null;
        $perPageRaw = $request->query->has('per_page') ? (int) $request->query->get('per_page') : null;
        $perPage = $perPageRaw !== null ? min(max(1, $perPageRaw), 100) : null;

        $serverTime = Carbon::now()->toIso8601String();

        // Agent ownership validation is done inside the service
        $result = $this->taskService->getTasksForUser($userId, $agentId, $since, $page, $perPage, $status);

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
     * POST /api/v1/tasks/{taskId}/tick
     *
     * Client-worker mode only — the browser's SharedWorker drives one
     * iteration of the agent loop here, mirrors the messenger-handler
     * path of {@see \Spora\Agents\Orchestrator::tick()}, and reports
     * the resulting task back to the SPA.
     *
     * The flow:
     *   1. 404 in server mode — the route stays registered but is gated
     *      inline so server-mode installs don't expose the surface.
     *   2. Per-user rate limit (60/min) — bursts from a runaway SharedWorker
     *      must not hammer PHP-FPM.
     *   3. set_time_limit(0) — the tick blocks for the LLM round-trip.
     *   4. 404 on not-owned (matches the abort/show precedent — existence
     *      hiding rather than 403).
     *   5. 400/409 on terminal or quiescent source status, or on a row
     *      that's already RUNNING.
     *   6. CAS-claim the row inside a transaction (status=QUEUED, no
     *      live lease) → RUNNING + lease_owner + lease_expires_at.
     *      Lost the race → 409 TICK_LOST_RACE so the browser can back off.
     *   7. Publish Mercure RUNNING BEFORE the LLM call so the UI flips
     *      status immediately, then call Orchestrator::tick() with a
     *      lease-aware config so the reaper can't race this request.
     *   8. On exception, flip the row to FAILED (mirror of
     *      WorkerQueueProcessor::processQueuedTaskSync() lines 87-106)
     *      and clear the lease.
     *   9. Once the row is terminal or quiescent, clear the lease —
     *      running/quiescent tasks are the only ones the reaper should
     *      consider orphans.
     */
    #[OA\Post(
        path: '/api/v1/tasks/{taskId}/tick',
        tags: ['Tasks'],
        summary: 'Tick a task (client-worker mode only)',
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
                description: 'JSON envelope: `{data: {task: ...}}` with the post-tick task resource.',
            ),
            new OA\Response(
                response: 404,
                description: 'NOT_FOUND — server mode is active or task is not owned by the calling user.',
            ),
            new OA\Response(
                response: 409,
                description: 'INVALID_STATE — task is terminal, quiescent, or being ticked by another caller.',
            ),
            new OA\Response(
                response: 429,
                description: 'TOO_MANY_REQUESTS — per-user rate limit exceeded.',
            ),
        ],
    )]
    public function tick(Request $request): JsonResponse
    {
        if ($this->workerRuntimeMode !== WorkerRuntimeMode::Client) {
            return $this->notFoundResponse();
        }

        $userId = (int) $this->authService->currentUserId();
        if (!$this->rateLimiter->attempt('tick:' . $userId, 60, 60)) {
            throw new TooManyRequestsException('Tick rate limit exceeded.');
        }

        set_time_limit(0);

        $taskId = (int) $request->attributes->get('taskId', 0);

        try {
            return $this->runTick($taskId, $userId);
        } catch (InvalidArgumentException $e) {
            // 404 on not-owned (matches abort/show precedent — plan finding #6);
            // 409 on not-drivable status — keep the typed exceptions so the
            // surrounding `abort`/`show` helper logic still works.
            return $this->errorForException($e);
        }
    }

    private function runTick(int $taskId, int $userId): JsonResponse
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        $policy = new TaskLifecyclePolicy();
        if ($policy->isTerminal($task->status) || $policy->isQuiescent($task->status)) {
            throw new InvalidArgumentException(
                'Task is not drivable in status "' . $task->status . '".',
            );
        }
        if ($task->status === 'RUNNING') {
            return new JsonResponse(
                ['error' => ['code' => 'TICK_ALREADY_RUNNING', 'message' => 'Task is already being ticked.']],
                Response::HTTP_CONFLICT,
            );
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $leaseUntilCarbon = Carbon::instance($now)->modify('+' . $this->tickLeaseSeconds . ' seconds');
        $leaseOwner = self::LEASE_OWNER_PREFIX . $userId;

        // CAS-claim the row inside a transaction so two browsers can't both
        // observe QUEUED + no live lease and flip the same task to RUNNING.
        $claimed = Capsule::connection()->transaction(function () use ($task, $leaseOwner, $leaseUntilCarbon, $now): ?Task {
            $row = Task::where('id', $task->id)
                ->where('status', 'QUEUED')
                ->where(function ($q) use ($now): void {
                    $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
                })
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return null;
            }
            $row->status = 'RUNNING';
            $row->lease_owner = $leaseOwner;
            $row->lease_expires_at = $leaseUntilCarbon;
            $row->save();
            return $row;
        });

        if ($claimed === null) {
            return new JsonResponse(
                ['error' => ['code' => 'TICK_LOST_RACE', 'message' => 'Task could not be claimed for ticking.']],
                Response::HTTP_CONFLICT,
            );
        }

        // Mercure publish BEFORE the LLM call so the UI sees QUEUED → RUNNING immediately.
        $this->mercure->publish($claimed->id, $claimed->user_id, [
            'task_id' => $claimed->id,
            'status'  => 'RUNNING',
        ]);

        // Mirror WorkerQueueProcessor::processQueuedTaskSync()'s exception path:
        // any thrown error from the orchestrator flips the row to FAILED so the
        // operator's UI surfaces the cause instead of leaving a phantom RUNNING.
        $orchestratorConfig = (new OrchestratorConfig())->withLease($leaseOwner, $this->tickLeaseSeconds);
        try {
            $this->orchestrator->tick($claimed->id, $orchestratorConfig);
        } catch (Throwable $e) {
            Task::where('id', $claimed->id)->where('status', 'RUNNING')->update([
                'status'           => 'FAILED',
                'failure_reason'   => $e->getMessage(),
                'error_code'       => 'UNKNOWN',
                'error_message'    => $e->getMessage(),
                'lease_owner'      => null,
                'lease_expires_at' => null,
            ]);
            $this->mercure->publish($claimed->id, $userId, [
                'task_id' => $claimed->id,
                'status'  => 'FAILED',
            ]);
        }

        $fresh = Task::find($claimed->id);
        if ($fresh !== null && ($policy->isTerminal($fresh->status) || $policy->isQuiescent($fresh->status))) {
            $fresh->lease_owner = null;
            $fresh->lease_expires_at = null;
            $fresh->save();
        }

        return new JsonResponse(['data' => ['task' => $this->taskService->getTask($claimed->id, $userId)]]);
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
