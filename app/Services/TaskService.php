<?php

declare(strict_types=1);

namespace Spora\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall;
use Throwable;

/**
 * Handles task CRUD operations, lifecycle state transitions, and real-time notifications.
 */
final class TaskService implements TaskServiceInterface
{
    private const ERR_TASK_NOT_FOUND = 'Task not found.';

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly MercurePublisherInterface $mercure,
        private readonly ?ToolCallSerializer $toolCallSerializer = null,
    ) {}

    /**
     * @inheritDoc
     */
    public function getTasksForUser(int $userId, ?int $agentId = null, ?string $since = null, ?int $page = null, ?int $perPage = null): array
    {
        $query = Task::where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->with(['agent']);

        if ($agentId !== null) {
            $query->where('agent_id', $agentId);
        }

        if ($since !== null) {
            try {
                $query->where('updated_at', '>', Carbon::parse($since)->utc());
            } catch (Throwable) {
                // Ignore invalid date format
            }
        }

        if ($page !== null) {
            $perPage = $perPage ?? 20;
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            return [
                'tasks' => $paginator->getCollection()->map(fn(Task $t) => $this->taskListResource($t))->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ];
        }

        return $query->get()->map(fn(Task $t) => $this->taskListResource($t))->all();
    }

    /**
     * @inheritDoc
     */
    /**
     * @param list<string> $mediaIds
     */
    public function startTask(int $userId, int $agentId, string $prompt, ?int $maxSteps = null, ?int $parentTaskId = null, array $mediaIds = []): array
    {
        $agent = Agent::where('id', $agentId)->where('user_id', $userId)->first();
        if ($agent === null) {
            throw new InvalidArgumentException('Agent not found.');
        }

        if ($parentTaskId !== null) {
            $parentTask = Task::where('id', $parentTaskId)->where('user_id', $userId)->first();
            if ($parentTask === null) {
                throw new InvalidArgumentException('parent_task_id is invalid.');
            }
        }

        $steps = $maxSteps ?? $agent->max_steps;
        $task = $this->orchestrator->start($agentId, $prompt, $steps, $parentTaskId, null, $mediaIds);

        $resource = $this->taskResource($task);
        $this->mercure->publish($task->id, $userId, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function getTask(int $taskId, int $userId): ?array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            return null;
        }
        return $this->taskResource($task);
    }

    /**
     * @inheritDoc
     */
    public function getTaskWithHistory(int $taskId, int $userId, ?int $sinceSequence = null): ?array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            return null;
        }
        return $this->taskDetailResource($task, $sinceSequence);
    }

    /**
     * @inheritDoc
     */
    public function approveTask(int $taskId, int $userId, array $decisions): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidArgumentException('Task is not pending approval.');
        }

        $this->orchestrator->resume($task->id, $decisions);
        $fresh = $task->fresh();

        $resource = $this->taskResource($fresh);
        $this->mercure->publish($fresh->id, $fresh->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function rejectTask(int $taskId, int $userId, string $reason): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidArgumentException('Task is not pending approval.');
        }

        $this->orchestrator->reject($task->id, $reason);
        $fresh = $task->fresh();

        $resource = $this->taskResource($fresh);
        $this->mercure->publish($fresh->id, $fresh->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function retryTask(int $taskId, int $userId): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if ($task->status !== 'FAILED') {
            throw new InvalidArgumentException('Only failed tasks can be retried.');
        }

        $retried = $this->orchestrator->retry($task->id);

        $resource = $this->taskResource($retried);
        $this->mercure->publish($retried->id, $retried->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    /**
     * @param list<string> $mediaIds
     */
    public function continueTask(int $taskId, int $userId, string $prompt, ?int $additionalSteps = null, array $mediaIds = []): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }

        if (!in_array($task->status, ['COMPLETED', 'FAILED'], true)) {
            throw new InvalidArgumentException('Can only continue completed or failed tasks.');
        }

        if ($additionalSteps !== null && ($additionalSteps < 1 || $additionalSteps > 100)) {
            throw new InvalidArgumentException('additional_steps must be an integer between 1 and 100.');
        }

        $continuedTask = $this->orchestrator->continue($task->id, $prompt, $additionalSteps, $mediaIds);

        $resource = $this->taskResource($continuedTask);
        $this->mercure->publish($continuedTask->id, $continuedTask->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function deleteTask(int $taskId, int $userId): bool
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            return false;
        }

        Capsule::connection()->transaction(function () use ($task): void {
            if ($task->retry_of_task_id === null) {
                Task::where('retry_of_task_id', $task->id)->delete();
            }
            TaskHistory::where('task_id', $task->id)->delete();
            ToolCall::where('task_id', $task->id)->delete();
            $task->delete();
        });

        return true;
    }

    /**
     * @inheritDoc
     */
    public function cancelRetryChain(int $taskId, int $userId): bool
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            return false;
        }

        if ($task->retry_of_task_id === null) {
            throw new InvalidArgumentException('This task is not part of a retry chain.');
        }

        // In-place retry means the "chain" is the failed task itself. Clearing
        // `retry_after` is enough to stop the worker from re-ticking it; the
        // task stays FAILED so the user can still see the failure or click
        // Retry Now manually.
        Capsule::table('tasks')
            ->where('user_id', $userId)
            ->where('retry_of_task_id', $task->retry_of_task_id)
            ->where('retry_count', '>=', $task->retry_count)
            ->where('status', 'FAILED')
            ->update([
                'retry_after'      => null,
                'retry_of_task_id' => null,
                'retry_count'      => 0,
            ]);

        return true;
    }

    /**
     * @return array{
     *     id: int,
     *     agent_id: int,
     *     status: string,
     *     user_prompt: string,
     *     final_response: string|null,
     *     step_count: int,
     *     max_steps: int,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     parent_task_id?: int,
     *     error_code?: string,
     *     error_message?: string,
     *     retry_of_task_id?: int,
     *     retry_count?: int,
     *     max_retries?: int,
     *     retry_after_minutes?: int,
     *     retry_after?: string,
     *     tool_calls: list<array<string, mixed>>,
     *     history: list<array<string, mixed>>,
     *     totals: array<string, int>
     * }
     */
    private function taskResource(Task $task, ?int $sinceSequence = null): array
    {
        $resource = $this->buildBaseTaskResource($task);

        $serializer = $this->toolCallSerializer ?? new ToolCallSerializer();
        $resource['tool_calls'] = $task->toolCalls->map(fn(ToolCall $tc) => $serializer->toArray($tc))->all();

        $historyQuery = $task->taskHistory()->orderBy('sequence');
        if ($sinceSequence !== null) {
            $historyQuery->where('sequence', '>', $sinceSequence);
        }

        $historyPayload = TaskHistorySerializer::buildHistoryPayload($historyQuery->get());
        $resource['history'] = $historyPayload['history'];
        $resource['totals'] = TaskHistorySerializer::aggregateUsage($historyPayload['usages']);

        return $resource;
    }

    /**
     * Build the common task fields used by both the detail and list resource views.
     *
     * @return array<string, mixed>
     */
    private function buildBaseTaskResource(Task $task): array
    {
        $resource = [
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

        if ($task->parent_task_id !== null) {
            $resource['parent_task_id'] = $task->parent_task_id;
        }

        if ($task->error_code !== null) {
            $resource['error_code'] = $task->error_code;
            $resource['error_message'] = $task->error_message;
        }

        if ($task->retry_of_task_id !== null) {
            $resource['retry_of_task_id'] = $task->retry_of_task_id;
            $resource['retry_count'] = $task->retry_count;
        } else {
            $resource['max_retries'] = $task->agent->max_retries ?? 0;
            $resource['retry_after_minutes'] = $task->agent->retry_after_minutes ?? 0;
        }

        if ($task->retry_after !== null) {
            $resource['retry_after'] = $task->retry_after->toIso8601String();
        }

        return $resource;
    }

    /**
     * Lightweight task representation for list views.
     * Excludes tool_calls and history to minimise payload size and avoid N+1 queries.
     *
     * @return array{
     *     id: int,
     *     agent_id: int,
     *     status: string,
     *     user_prompt: string,
     *     final_response: string|null,
     *     step_count: int,
     *     max_steps: int,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     parent_task_id?: int,
     *     error_code?: string,
     *     error_message?: string,
     *     retry_of_task_id?: int,
     *     retry_count?: int,
     *     max_retries?: int,
     *     retry_after_minutes?: int,
     *     retry_after?: string
     * }
     */
    private function taskListResource(Task $task): array
    {
        return $this->buildBaseTaskResource($task);
    }

    /**
     * @return array{
     *     id: int,
     *     agent_id: int,
     *     status: string,
     *     user_prompt: string,
     *     final_response: string|null,
     *     step_count: int,
     *     max_steps: int,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     parent_task_id?: int,
     *     error_code?: string,
     *     error_message?: string,
     *     retry_of_task_id?: int,
     *     retry_count?: int,
     *     max_retries?: int,
     *     retry_after_minutes?: int,
     *     retry_after?: string,
     *     tool_calls: list<array<string, mixed>>,
     *     history: list<array<string, mixed>>,
     *     totals: array<string, int>
     * }
     */
    private function taskDetailResource(Task $task, ?int $sinceSequence = null): array
    {
        // Single source of truth: taskResource now honours sinceSequence and
        // serialises tool_calls via ToolCallSerializer (Shape A parity with
        // the Mercure live stream — operation, operation_description, and
        // parameter_schema all flow through). Re-running the queries here
        // would duplicate work and re-introduce the Shape A/B divergence.
        return $this->taskResource($task, $sinceSequence);
    }

}
