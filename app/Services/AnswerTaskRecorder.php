<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Models\Task;
use Spora\Tools\PendingQuestionBatch;
use Throwable;

/**
 * Persists the operator's answers to a pending `ask_user_question` batch
 * on a Task row.
 *
 * Returns the freshly-loaded Task + its owning principal id so the
 * caller can build the response resource and publish to Mercure —
 * keeping the resource shape + publish failure handling on the service
 * where they belong, while this class owns only the transactional
 * state mutation.
 */
final class AnswerTaskRecorder
{
    public const ERR_TASK_NOT_FOUND = 'Task not found.';

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly ?PrincipalResolver $principalResolver = null,
    ) {}

    /**
     * @return array{task: Task, principal_id: int}
     */
    public function record(int $taskId, int $userId, string $toolCallId, string $formattedContent): array
    {
        return Capsule::connection()->transaction(function () use ($taskId, $userId, $toolCallId, $formattedContent): array {
            $task = $this->loadAwaitingTask($taskId, $userId);
            $this->recordAnswerHistory($task, $toolCallId, $formattedContent);
            $this->persistAnsweredState($task, $toolCallId);
            $fresh = $task->fresh();
            // Capture principal_id inside the transaction so the
            // post-commit publish doesn't re-query the row.
            return ['task' => $fresh, 'principal_id' => $fresh->principalOwnerId()];
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function loadAwaitingTask(int $taskId, int $userId): Task
    {
        $visiblePrincipalIds = $this->principalResolver?->visiblePrincipalIds($userId) ?? [];
        $task = Task::where('id', $taskId)
            ->whereIn('principal_id', $visiblePrincipalIds)
            ->lockForUpdate()
            ->first();
        if ($task === null) {
            throw new InvalidArgumentException(self::ERR_TASK_NOT_FOUND);
        }
        if ($task->status !== 'AWAITING_INPUT') {
            throw new InvalidArgumentException('Task is not awaiting input.');
        }
        return $task;
    }

    /**
     * Append exactly one tool history row per batch — mirrors the
     * orchestrator's batched-tool-call pattern. The LLM sees a single
     * coherent answer block, not per-question fragments.
     */
    private function recordAnswerHistory(Task $task, string $toolCallId, string $formattedContent): void
    {
        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: $formattedContent,
            context: new HistoryMessageContext(
                toolCallId: $toolCallId,
                toolName: 'ask_user_question',
            ),
        );
    }

    /**
     * Drop the answered batch from pending_state. If another batch is
     * still pending (multiple ask_user_question calls in different
     * turns), keep AWAITING_INPUT — the operator still has work to do.
     */
    private function persistAnsweredState(Task $task, string $toolCallId): void
    {
        $remaining = $this->remainingPendingQuestions($task, $toolCallId);
        if ($remaining === []) {
            $task->status = 'QUEUED';
            $task->pending_state = null;
        } else {
            $state = new AgentState(
                taskId: $task->id,
                agentId: $task->agent_id,
                pendingToolCalls: [],
                messageSnapshot: [],
                stepCount: $task->step_count,
                maxSteps: $task->max_steps,
                pausedAt: gmdate('Y-m-d\TH:i:s\Z'),
                pendingQuestions: $remaining,
            );
            $task->pending_state = $state->toJson();
        }
        $task->save();
    }

    /**
     * @return list<PendingQuestionBatch>
     */
    private function remainingPendingQuestions(Task $task, string $answeredToolCallId): array
    {
        if (!is_string($task->pending_state) || $task->pending_state === '') {
            return [];
        }
        try {
            $existing = AgentState::fromJson($task->pending_state);
        } catch (Throwable) {
            // Malformed pending_state — drop the column entirely rather
            // than carry forward corrupted data.
            return [];
        }
        $remaining = [];
        foreach ($existing->pendingQuestions as $candidate) {
            if ($candidate->toolCallId !== $answeredToolCallId) {
                $remaining[] = $candidate;
            }
        }
        return $remaining;
    }
}
