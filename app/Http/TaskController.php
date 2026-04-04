<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Agents\OrchestratorInterface;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskController
{
    public function __construct(
        private readonly AuthService           $authService,
        private readonly OrchestratorInterface $orchestrator,
    ) {}

    /**
     * GET /api/v1/tasks
     */
    public function index(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $tasks = Task::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(Task $t) => $this->taskResource($t));

        return new JsonResponse(['data' => ['tasks' => $tasks->all()]]);
    }

    /**
     * POST /api/v1/tasks
     */
    public function store(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $body   = $this->decodeJson($request);
        $prompt = trim((string) ($body['prompt'] ?? ''));

        if ($prompt === '') {
            return new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'prompt is required.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $agent = Agent::where('user_id', $userId)->first();

        if ($agent === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'No agent found for this user.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $maxSteps = isset($body['max_steps']) ? (int) $body['max_steps'] : $agent->max_steps;
        $task     = $this->orchestrator->start($agent->id, $prompt, $maxSteps);

        return new JsonResponse(
            ['data' => ['task' => $this->taskResource($task)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/tasks/{taskId}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $task   = $this->findTask((int) $request->attributes->get('taskId', 0), $userId);

        if ($task === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $sinceSequence = null;
        if ($request->query->has('since_sequence')) {
            $sinceSequence = (int) $request->query->get('since_sequence');
            if ($sinceSequence < 0) {
                $sinceSequence = null;
            }
        }

        return new JsonResponse(['data' => ['task' => $this->taskDetailResource($task, $sinceSequence)]]);
    }

    /**
     * POST /api/v1/tasks/{taskId}/approve
     */
    public function approve(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $task   = $this->findTask((int) $request->attributes->get('taskId', 0), $userId);

        if ($task === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_STATE', 'message' => 'Task is not pending approval.']],
                Response::HTTP_CONFLICT,
            );
        }

        $body = $this->decodeJson($request);

        // Accept either a modern batch payload  { "approvals": [{ "provider_call_id": "...", "arguments": {...} }] }
        // or the legacy single-tool format       { "arguments": {...} }  (auto-wrapped for backward compatibility).
        if (isset($body['approvals']) && is_array($body['approvals'])) {
            $approvedBatch = $body['approvals'];
        } else {
            $approvedBatch = [[
                'provider_call_id' => (string) ($body['provider_call_id'] ?? ''),
                'arguments'        => (array) ($body['arguments'] ?? []),
            ]];
        }

        $this->orchestrator->resume($task->id, $approvedBatch);

        return new JsonResponse(['data' => ['task' => $this->taskResource($task->fresh())]]);
    }

    /**
     * POST /api/v1/tasks/{taskId}/reject
     */
    public function reject(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $task   = $this->findTask((int) $request->attributes->get('taskId', 0), $userId);

        if ($task === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_STATE', 'message' => 'Task is not pending approval.']],
                Response::HTTP_CONFLICT,
            );
        }

        $body   = $this->decodeJson($request);
        $reason = trim((string) ($body['reason'] ?? 'No reason provided.'));

        $this->orchestrator->reject($task->id, $reason);

        return new JsonResponse(['data' => ['task' => $this->taskResource($task->fresh())]]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function findTask(int $id, int $userId): ?Task
    {
        return Task::where('id', $id)->where('user_id', $userId)->first();
    }

    private function taskResource(Task $task): array
    {
        return [
            'id'             => $task->id,
            'status'         => $task->status,
            'user_prompt'    => $task->user_prompt,
            'final_response' => $task->final_response,
            'step_count'     => $task->step_count,
            'max_steps'      => $task->max_steps,
            'created_at'     => $task->created_at?->toIso8601String(),
            'updated_at'     => $task->updated_at?->toIso8601String(),
        ];
    }

    private function taskDetailResource(Task $task, ?int $sinceSequence = null): array
    {
        $resource               = $this->taskResource($task);
        $resource['tool_calls'] = $task->toolCalls->map(fn(ToolCall $tc) => [
            'id'                 => $tc->id,
            'tool_name'          => $tc->tool_name,
            'tool_type'          => $tc->tool_type,
            'status'             => $tc->status,
            'proposed_arguments' => $tc->proposed_arguments,
            'approved_arguments' => $tc->approved_arguments,
            'human_description'  => $tc->human_description,
            'result_content'     => $tc->result_content,
            'executed_at'        => $tc->executed_at?->toIso8601String(),
        ])->all();

        $historyQuery = $task->taskHistory();
        if ($sinceSequence !== null) {
            $historyQuery->where('sequence', '>', $sinceSequence);
        }

        $resource['history'] = $historyQuery->get()->map(fn(TaskHistory $h) => [
            'sequence'     => $h->sequence,
            'role'         => $h->role,
            'content'      => $h->content,
            'tool_call_id' => $h->tool_call_id,
            'tool_name'    => $h->tool_name,
        ])->all();

        return $resource;
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true) ?? [];
    }
}
