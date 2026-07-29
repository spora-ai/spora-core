<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\ScrubDataUrls;

/**
 * Owns the orchestrator's load / build / transition helpers for both the
 * per-call `resume()` path and the task-level `reject()` path.
 *
 * Keeps the orchestrator class focused on the tick loop while centralising
 * the "is the task still awaiting approval?", "what is its serialized
 * state?", and "what status should it transition to after approval?" rules.
 *
 * Holds an orchestrator reference only to invoke `appendHistory` and `tick`
 * from the existing helper-method boundary — no business state is shared.
 */
final class AgentStateResolver
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly WorkerMode $workerMode,
    ) {}

    public function loadPendingTask(int $taskId): Task
    {
        $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();
        if ($task->status !== 'PENDING_APPROVAL') {
            throw new InvalidTaskTransitionException("Task {$taskId} is not awaiting approval.");
        }
        return $task;
    }

    public function loadAgentState(Task $task): AgentState
    {
        return $task->pending_state === null
            ? new AgentState(
                taskId: $task->id,
                agentId: $task->agent_id,
                pendingToolCalls: [],
                messageSnapshot: [],
                stepCount: $task->step_count,
                maxSteps: $task->max_steps,
                pausedAt: date(Orchestrator::ISO8601_UTC_FORMAT),
            )
            : AgentState::fromJson($task->pending_state);
    }

    /**
     * @param list<array{provider_call_id: string, reason: string}> $rejectedBatch
     */
    public function buildRemainingAgentState(AgentState $state, array $rejectedBatch): AgentState
    {
        $rejectedIds = array_column($rejectedBatch, 'provider_call_id');
        $remainingCalls = array_values(array_filter(
            $state->pendingToolCalls,
            static fn($call): bool => !in_array($call->providerCallId, $rejectedIds, true),
        ));
        return new AgentState(
            taskId: $state->taskId,
            agentId: $state->agentId,
            pendingToolCalls: $remainingCalls,
            messageSnapshot: $state->messageSnapshot,
            stepCount: $state->stepCount,
            maxSteps: $state->maxSteps,
            pausedAt: date(Orchestrator::ISO8601_UTC_FORMAT),
        );
    }

    /**
     * @param list<array{provider_call_id: string, arguments: array<string, mixed>}> $approvedBatch
     */
    public function applyResumeTransition(Task $task, array $approvedBatch, AgentState $remainingState): bool
    {
        if ($approvedBatch !== []) {
            $task->pending_state = $remainingState->toJson();
            $task->save();
            return $this->workerMode === WorkerMode::Sync;
        }

        $hasPendingApprovals = ToolCallModel::where('task_id', $task->id)
            ->where('status', 'PENDING_APPROVAL')
            ->exists();
        if ($hasPendingApprovals) {
            $task->pending_state = $remainingState->toJson();
            $task->save();
            return false;
        }

        $task->pending_state = null;
        $task->status = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        $task->save();
        return $this->workerMode === WorkerMode::Sync;
    }

    public function recordBulkRejection(Task $task, string $reason): void
    {
        $pendingModels = ToolCallModel::where('task_id', $task->id)
            ->where('status', 'PENDING_APPROVAL')
            ->get();
        ToolCallModel::where('task_id', $task->id)
            ->where('status', 'PENDING_APPROVAL')
            ->update(['status' => 'REJECTED']);
        foreach ($pendingModels as $model) {
            $this->appendRejectionHistory($task, $model, $reason);
        }
    }

    public function appendRejectionHistory(Task $task, ToolCallModel $model, string $reason): void
    {
        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub("Action rejected by user: {$reason}"),
            context: new HistoryMessageContext(
                toolCallId: $model->provider_call_id,
                toolName: $model->tool_name,
            ),
        );
    }

    /**
     * Updates the task's status after a task-level `reject()` and triggers a
     * follow-up `tick()` only in Sync mode so the LLM round-trip does not
     * hold the lockForUpdate open.
     *
     * @return bool true when `tick()` was invoked; false otherwise.
     */
    public function transitionTaskAfterRejection(int $taskId): bool
    {
        $taskStatus = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        Task::where('id', $taskId)->update(['status' => $taskStatus]);
        if ($this->workerMode !== WorkerMode::Sync) {
            return false;
        }
        $this->orchestrator->tick($taskId);
        return true;
    }
}
