<?php

declare(strict_types=1);

namespace Spora\Services;

use Carbon\Carbon;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Models\Task;
use Spora\Models\ToolCall;
use Spora\Tools\PendingQuestionBatch;
use Throwable;

/**
 * Builds the wire shape of a task: the base fields, the tool calls, the
 * history and its usage totals, and the detail-only extras.
 *
 * Split out of {@see TaskService} so the wire shape lives in one place
 * next to the sanitisation rules it applies, and so the service stays on
 * the task lifecycle rather than on its presentation.
 */
final class TaskResourceBuilder
{
    public function __construct(
        private readonly ?ToolCallSerializer $toolCallSerializer = null,
    ) {}

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
    public function taskResource(Task $task, ?int $sinceSequence = null): array
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
    public function buildBaseTaskResource(Task $task): array
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

        $abortedAt = is_array($task->data) ? ($task->data['aborted_at'] ?? null) : null;
        if ($abortedAt !== null) {
            try {
                $resource['aborted_at'] = Carbon::parse($abortedAt)->utc()->toIso8601String();
            } catch (Throwable) {
                $resource['aborted_at'] = null;
            }
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
    public function taskListResource(Task $task): array
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
     *     totals: array<string, int>,
     *     data: array<string, mixed>|null,
     *     pending_questions: list<array<string, mixed>>|null
     * }
     */
    public function taskDetailResource(Task $task, ?int $sinceSequence = null): array
    {
        // Single source of truth: taskResource now honours sinceSequence and
        // serialises tool_calls via ToolCallSerializer (Shape A parity with
        // the Mercure live stream — operation, operation_description, and
        // parameter_schema all flow through). Re-running the queries here
        // would duplicate work and re-introduce the Shape A/B divergence.
        $resource = $this->taskResource($task, $sinceSequence);

        // Surface `tasks.data` (TodoTool writes `data.todos`,
        // SubAgentTool writes `data.spawned_sub_task_ids`, etc.) and any
        // pending ask_user_question batches alongside the tool-call
        // history so the REST poll mirrors the Mercure `data` /
        // `pending_questions` fields — keeps Mercure-less deployments
        // (php -S dev server, no hub) on par with the live stream.
        // Scoped to the detail endpoint only: action responses
        // (start/approve/reject/retry/continue/abort/answer) and
        // Mercure payloads go through `taskResource()` directly and
        // must NOT carry these fields, so the list view stays minimal
        // and the action-level wire shapes don't drift.
        $resource['data'] = $task->data;

        $resource['pending_questions'] = null;
        if (is_string($task->pending_state) && $task->pending_state !== '') {
            try {
                $state = AgentState::fromJson($task->pending_state);
                if ($state->pendingQuestions !== []) {
                    $resource['pending_questions'] = array_map(
                        static fn(PendingQuestionBatch $b): array => $b->toArray(),
                        $state->pendingQuestions,
                    );
                }
            } catch (Throwable) {
                // Bad JSON or shape drift — fall through with null (defensive,
                // mirrors TickPhaseRunner::publishIntermediateState).
            }
        }

        return $resource;
    }
}
