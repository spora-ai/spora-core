<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Agents\OrchestratorInterface;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Spora\Services\MercurePublisherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskController
{
    public function __construct(
        private readonly AuthService             $authService,
        private readonly OrchestratorInterface $orchestrator,
        private readonly MercurePublisherInterface $mercure,
    ) {}

    /**
     * GET /api/v1/tasks
     * Optional ?agent_id=X query param to scope results to a specific agent.
     */
    public function index(Request $request): JsonResponse
    {
        $userId  = AuthGuard::requireAuth($this->authService);
        $agentId = $request->query->has('agent_id') ? (int) $request->query->get('agent_id') : null;

        $query = Task::where('user_id', $userId)->orderByDesc('created_at');

        if ($agentId !== null) {
            // Verify the agent belongs to this user before filtering
            $agent = Agent::where('id', $agentId)->where('user_id', $userId)->first();
            if ($agent === null) {
                return new JsonResponse(
                    ['error' => ['code' => 'NOT_FOUND', 'message' => 'Agent not found.']],
                    Response::HTTP_NOT_FOUND,
                );
            }
            $query->where('agent_id', $agentId);
        }

        $tasks = $query->get()->map(fn(Task $t) => $this->taskResource($t));

        return new JsonResponse(['data' => ['tasks' => $tasks->all()]]);
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

        $agent = Agent::where('id', $agentId)->where('user_id', $userId)->first();

        if ($agent === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Agent not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $maxSteps = isset($body['max_steps']) ? (int) $body['max_steps'] : $agent->max_steps;
        $task = $this->orchestrator->start($agent->id, $prompt, $maxSteps);
        $this->mercure->publish($task->id, $this->taskResource($task));

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

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Accept either a modern batch payload  { "approvals": [{ "provider_call_id": "...", "arguments": {...} }] }
        // or the legacy single-tool format       { "arguments": {...} }  (auto-wrapped for backward compatibility).
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

        $this->orchestrator->resume($task->id, $approvedBatch);
        $fresh = $task->fresh();
        $this->mercure->publish($fresh->id, $this->taskResource($fresh));

        return new JsonResponse(['data' => ['task' => $this->taskResource($fresh)]]);
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

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $reason = trim((string) ($body['reason'] ?? 'No reason provided.'));

        $this->orchestrator->reject($task->id, $reason);
        $fresh = $task->fresh();
        $this->mercure->publish($fresh->id, $this->taskResource($fresh));

        return new JsonResponse(['data' => ['task' => $this->taskResource($fresh)]]);
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
            'agent_id'       => $task->agent_id,
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

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
