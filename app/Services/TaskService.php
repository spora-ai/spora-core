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
    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly MercurePublisherInterface $mercure,
    ) {}

    /**
     * @inheritDoc
     */
    public function getTasksForUser(int $userId, ?int $agentId = null, ?string $since = null): array
    {
        $query = Task::where('user_id', $userId)
            ->orderByDesc('created_at')
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

        return $query->get()->map(fn(Task $t) => $this->taskListResource($t))->all();
    }

    /**
     * @inheritDoc
     */
    public function startTask(int $userId, int $agentId, string $prompt, ?int $maxSteps = null, ?int $parentTaskId = null): array
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
        $task = $this->orchestrator->start($agentId, $prompt, $steps, $parentTaskId);

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
    public function approveTask(int $taskId, int $userId, array $approvals): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException('Task not found.');
        }

        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidArgumentException('Task is not pending approval.');
        }

        $this->orchestrator->resume($task->id, $approvals);
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
            throw new InvalidArgumentException('Task not found.');
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
            throw new InvalidArgumentException('Task not found.');
        }

        if ($task->status !== 'FAILED') {
            throw new InvalidArgumentException('Only failed tasks can be retried.');
        }

        $newTask = $this->orchestrator->start($task->agent_id, $task->user_prompt, $task->max_steps);

        $resource = $this->taskResource($newTask);
        $this->mercure->publish($newTask->id, $newTask->user_id, $resource);

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function continueTask(int $taskId, int $userId, string $prompt, ?int $additionalSteps = null): array
    {
        $task = Task::where('id', $taskId)->where('user_id', $userId)->first();
        if ($task === null) {
            throw new InvalidArgumentException('Task not found.');
        }

        if (!in_array($task->status, ['COMPLETED', 'FAILED'], true)) {
            throw new InvalidArgumentException('Can only continue completed or failed tasks.');
        }

        if ($additionalSteps !== null && ($additionalSteps < 1 || $additionalSteps > 100)) {
            throw new InvalidArgumentException('additional_steps must be an integer between 1 and 100.');
        }

        $continuedTask = $this->orchestrator->continue($task->id, $prompt, $additionalSteps);

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

        Capsule::table('tasks')
            ->where('user_id', $userId)
            ->where('retry_of_task_id', $task->retry_of_task_id)
            ->where('retry_count', '>=', $task->retry_count)
            ->update(['status' => 'CANCELLED']);

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
     *     retry_after?: string
     * }
     */
    private function taskResource(Task $task): array
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
            $agent = Agent::find($task->agent_id);
            $resource['max_retries'] = $agent->max_retries ?? 0;
            $resource['retry_after_minutes'] = $agent->retry_after_minutes ?? 0;
        }

        if ($task->retry_after !== null) {
            $resource['retry_after'] = $task->retry_after->toIso8601String();
        }

        $resource['tool_calls'] = $task->toolCalls->map(fn(ToolCall $tc) => [
            'id'                    => $tc->id,
            'provider_call_id'      => $tc->provider_call_id,
            'tool_name'             => $tc->tool_name,
            'tool_type'             => $tc->tool_type,
            'status'                => $tc->status,
            'proposed_arguments'    => $tc->proposed_arguments,
            'approved_arguments'    => $tc->approved_arguments,
            'human_description'     => $tc->human_description,
            'operation'             => $tc->operation,
            'operation_description' => $tc->operation_description,
            'result_content'        => $tc->result_content,
            'executed_at'           => $tc->executed_at?->toIso8601String(),
        ])->all();

        $resource['history'] = $task->taskHistory()->orderBy('sequence')->get()->map(fn(TaskHistory $h) => [
            'sequence'     => $h->sequence,
            'role'         => $h->role,
            'content'      => $h->content,
            'reasoning'    => $h->reasoning,
            'tool_call_id' => $h->tool_call_id,
            'tool_name'    => $h->tool_name,
        ])->all();

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
            // Use eager-loaded agent relation to avoid a per-task query
            $resource['max_retries'] = $task->agent->max_retries ?? 0;
            $resource['retry_after_minutes'] = $task->agent->retry_after_minutes ?? 0;
        }

        if ($task->retry_after !== null) {
            $resource['retry_after'] = $task->retry_after->toIso8601String();
        }

        return $resource;
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
     *     tool_calls: list<array{
     *         id: int,
     *         tool_name: string,
     *         tool_type: string,
     *         status: string,
     *         proposed_arguments: array|null,
     *         approved_arguments: array|null,
     *         human_description: string|null,
     *         result_content: string|null,
     *         executed_at: string|null
     *     }>,
     *     history: list<array{
     *         sequence: int,
     *         role: string,
     *         content: string|null,
     *         reasoning: string|null,
     *         tool_call_id: string|null,
     *         tool_name: string|null
     *     }>
     * }
     */
    private function taskDetailResource(Task $task, ?int $sinceSequence = null): array
    {
        $resource = $this->taskResource($task);

        $resource['tool_calls'] = $task->toolCalls->map(fn(ToolCall $tc) => [
            'id'                 => $tc->id,
            'provider_call_id'   => $tc->provider_call_id,
            'tool_name'          => $tc->tool_name,
            'tool_type'          => $tc->tool_type,
            'status'             => $tc->status,
            'proposed_arguments' => $tc->proposed_arguments,
            'approved_arguments' => $tc->approved_arguments,
            'human_description'  => $tc->human_description,
            'result_content'     => $tc->result_content,
            'executed_at'        => $tc->executed_at?->toIso8601String(),
        ])->all();

        $historyQuery = $task->taskHistory()->orderBy('sequence');
        if ($sinceSequence !== null) {
            $historyQuery->where('sequence', '>', $sinceSequence);
        }

        $resource['history'] = $historyQuery->get()->map(fn(TaskHistory $h) => [
            'sequence'     => $h->sequence,
            'role'         => $h->role,
            'content'      => $h->content,
            'reasoning'    => $h->reasoning,
            'tool_call_id' => $h->tool_call_id,
            'tool_name'    => $h->tool_name,
        ])->all();

        return $resource;
    }
}
