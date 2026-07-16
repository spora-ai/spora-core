<?php

declare(strict_types=1);

namespace Spora\Http;

use Carbon\Carbon;
use InvalidArgumentException;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaCapabilityMismatchException;
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

    public function __construct(
        private readonly AuthService $authService,
        private readonly TaskServiceInterface $taskService,
        private readonly ?DriverFactory $driverFactory = null,
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
        $mediaIds = $this->parseMediaIds($body['media_ids'] ?? null);

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
                $this->ensureMediaCapabilityCompatible($agentId, $mediaIds);
            } catch (MediaCapabilityMismatchException $e) {
                return new JsonResponse(
                    ['error' => ['code' => 'MEDIA_CAPABILITY_MISMATCH', 'message' => $e->getMessage()]],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            try {
                $task = $this->taskService->startTask($userId, $agentId, $prompt, $maxSteps, $parentTaskId, $mediaIds);
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

    /** @return list<string> */
    private function parseMediaIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            if (is_string($id) && $id !== '') {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Reject an image attachment when the agent's LLM cannot consume image
     * blocks. Plan §8.3 / §12 require a 400 at the request boundary rather
     * than a silent image-strip during the first tick. {@see MessageHistoryBuilder}
     * still strips defensively — this pre-flight gives the caller a useful error.
     *
     * @param list<string> $mediaIds
     * @throws MediaCapabilityMismatchException
     */
    private function ensureMediaCapabilityCompatible(int $agentId, array $mediaIds): void
    {
        if ($mediaIds === [] || $this->driverFactory === null) {
            return;
        }
        if (!$this->mediaIdsIncludeImage($mediaIds)) {
            return;
        }
        if (!$this->agentSupportsImages($agentId)) {
            throw new MediaCapabilityMismatchException(
                'One or more attachments are images but the agent\'s LLM does not support image input.',
            );
        }
    }

    /**
     * @param list<string> $mediaIds
     */
    private function mediaIdsIncludeImage(array $mediaIds): bool
    {
        foreach ($mediaIds as $mid) {
            if ($mid === '') {
                continue;
            }
            $asset = MediaAsset::query()->find($mid);
            if ($asset === null) {
                continue;
            }
            if (is_string($asset->mime_type) && str_starts_with(strtolower($asset->mime_type), 'image/')) {
                return true;
            }
        }

        return false;
    }

    private function agentSupportsImages(int $agentId): bool
    {
        $agent = Agent::query()->find($agentId);
        if ($agent === null) {
            return false;
        }
        try {
            $driver = $this->driverFactory->makeFromAgent($agent);
        } catch (Throwable) {
            return false;
        }

        return $driver->supportsImageInput();
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

        return $this->performApproval($taskId, $userId, $batch);
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
        $batch = $this->extractApprovalBatchItems($body);
        if ($batch instanceof JsonResponse) {
            return $batch;
        }

        $validation = $this->validateAndNormalizeApprovalBatch($batch);
        return $validation ?? $batch;
    }

    /**
     * @return list<array<string, mixed>>|JsonResponse
     */
    private function extractApprovalBatchItems(array $body): array|JsonResponse
    {
        if (isset($body['approvals']) && is_array($body['approvals'])) {
            return $body['approvals'];
        }

        $providerId = trim((string) ($body['provider_call_id'] ?? ''));
        if ($providerId === '') {
            return new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'provider_call_id is required.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return [[
            'provider_call_id' => $providerId,
            'arguments'        => (array) ($body['arguments'] ?? []),
        ]];
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function validateAndNormalizeApprovalBatch(array &$batch): ?JsonResponse
    {
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
        return null;
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function performApproval(int $taskId, int $userId, array $batch): JsonResponse
    {
        try {
            $task = $this->taskService->approveTask($taskId, $userId, $batch);
            return new JsonResponse(['data' => ['task' => $task]]);
        } catch (InvalidArgumentException $e) {
            return $this->approvalErrorResponse($e);
        }
    }

    private function approvalErrorResponse(InvalidArgumentException $e): JsonResponse
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
            'mediaIds' => $this->parseMediaIds($body['media_ids'] ?? null),
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
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => self::ERR_TASK_NOT_FOUND]],
                Response::HTTP_NOT_FOUND,
            );
        }
        try {
            $this->ensureMediaCapabilityCompatible((int) $existing['agent_id'], $mediaIds);
        } catch (MediaCapabilityMismatchException $e) {
            return new JsonResponse(
                ['error' => ['code' => 'MEDIA_CAPABILITY_MISMATCH', 'message' => $e->getMessage()]],
                Response::HTTP_BAD_REQUEST,
            );
        }
        try {
            $task = $this->taskService->continueTask($taskId, $userId, $prompt, $additionalSteps, $mediaIds);

            return new JsonResponse(
                ['data' => ['task' => $task]],
                Response::HTTP_OK,
            );
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === self::ERR_TASK_NOT_FOUND
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
