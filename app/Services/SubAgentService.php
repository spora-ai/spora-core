<?php

declare(strict_types=1);

namespace Spora\Services;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\Text\Utf8Sanitizer;

/**
 * Spawns child tasks from a `HandoverTool` `sub_agent` invocation and
 * resumes the parent once every child reaches a terminal state.
 *
 * Authorization mirrors {@see HandoverService::handover()}: the caller
 * must own both the parent task and the target agent. Cross-user
 * delegation is out of scope.
 *
 * The tool_call_id ↔ child_id mapping is kept on the persisted
 * `tool_calls` row (`result_data.spawned_sub_task_id`) by the tool
 * itself, so the resume path reads it back as a single SELECT — no
 * extra plumbing through the orchestrator.
 */
final class SubAgentService implements SubAgentServiceInterface
{
    /**
     * @param Closure(): OrchestratorInterface $orchestratorFactory
     *   Lazy factory — the Orchestrator's constructor takes the tool
     *   instance list (which includes `HandoverTool`), so direct
     *   injection creates a circular dependency. The closure defers
     *   resolution to the moment `spawn()` is called, mirroring the
     *   pattern used by {@see HandoverService}.
     */
    public function __construct(
        private readonly Closure $orchestratorFactory,
        private readonly ?MercurePublisherInterface $mercure = null,
        private readonly WorkerMode $workerMode = WorkerMode::Sync,
    ) {}

    public function spawn(int $parentTaskId, int $targetAgentId, string $prompt, int $userId, ?string $toolCallId = null): Task
    {
        $parent = Task::where('id', $parentTaskId)
            ->where('user_id', $userId)
            ->first();
        if ($parent === null) {
            throw new InvalidArgumentException('Parent task not found.');
        }

        $targetAgent = Agent::where('id', $targetAgentId)
            ->where('user_id', $userId)
            ->first();
        if ($targetAgent === null) {
            throw new InvalidArgumentException('Target agent not found.');
        }

        // Flip the parent to AWAITING_SUB_AGENTS BEFORE the child tick
        // runs. In Sync mode Orchestrator::start() ticks the child inline,
        // so the child may complete before we get a chance to update the
        // parent — and the parent's status is what gates
        // `maybeResumeParent` on the child's completion.
        $this->markParentAwaitingSubAgents($parent);

        $child = ($this->orchestratorFactory)()->start(
            agentId: $targetAgent->id,
            userPrompt: $prompt,
            maxSteps: (int) ($targetAgent->max_steps ?? 10),
            parentTaskId: $parent->id,
        );

        $this->recordSpawnedChild($parent, $child->id);

        // In Sync mode the child has already ticked (and may have
        // completed) by the time we get here. The `maybeResumeParent` hook
        // fires from inside the child's tick — but at that moment the
        // parent had no `spawned_sub_task_ids` recorded yet, so it returned
        // early. Re-check now that the registration is committed.
        $child->refresh();
        if (in_array($child->status, ['COMPLETED', 'FAILED'], true)) {
            $this->maybeResumeParent($child->id);
        }

        $this->publishParentState($parent->id, $userId);

        return $child;
    }

    public function maybeResumeParent(int $childTaskId): void
    {
        $child = Task::find($childTaskId);
        if ($child === null || $child->parent_task_id === null) {
            return;
        }
        if (!in_array($child->status, ['COMPLETED', 'FAILED'], true)) {
            return;
        }

        $parent = Task::find($child->parent_task_id);
        if ($parent === null || $parent->status !== 'AWAITING_SUB_AGENTS') {
            return;
        }

        $siblingIds = $this->extractSpawnedChildIds($parent);
        if ($siblingIds === []) {
            return;
        }

        foreach ($siblingIds as $siblingId) {
            $sibling = Task::find($siblingId);
            if ($sibling === null || !in_array($sibling->status, ['COMPLETED', 'FAILED'], true)) {
                return;
            }
        }

        $this->resumeParent($parent, $siblingIds);
    }

    private function recordSpawnedChild(Task $parent, int $childId): void
    {
        $data = $parent->data ?? [];

        $spawned = $data['spawned_sub_task_ids'] ?? [];
        if (!in_array($childId, $spawned, true)) {
            $spawned[] = $childId;
            $data['spawned_sub_task_ids'] = $spawned;

            Capsule::table('tasks')
                ->where('id', $parent->id)
                ->update(['data' => json_encode($data, JSON_THROW_ON_ERROR)]);
        }
    }

    /**
     * Idempotently flip the parent to AWAITING_SUB_AGENTS and seed
     * `data.spawned_sub_task_ids` so the resume path can walk the live
     * set of children. Called BEFORE the child tick so the child
     * completion's `maybeResumeParent` hook sees the right parent state.
     */
    private function markParentAwaitingSubAgents(Task $parent): void
    {
        $data = $parent->data ?? [];
        $data['spawned_sub_task_ids'] = $data['spawned_sub_task_ids'] ?? [];

        Capsule::table('tasks')
            ->where('id', $parent->id)
            ->update([
                'status' => 'AWAITING_SUB_AGENTS',
                'data'   => json_encode($data, JSON_THROW_ON_ERROR),
            ]);
    }

    /**
     * Flip the parent back to RUNNING/QUEUED, append `role:'tool'` rows
     * for each completed child, clear the spawned-child book-keeping
     * in `data`, and kick the next tick (or let the daemon pick it up
     * in Worker mode).
     *
     * @param list<int> $childIds
     */
    private function resumeParent(Task $parent, array $childIds): void
    {
        $orchestrator = ($this->orchestratorFactory)();

        foreach ($childIds as $childId) {
            $child = Task::find($childId);
            if ($child === null) {
                continue;
            }

            $orchestrator->appendHistory(
                taskId: $parent->id,
                role: 'tool',
                content: $this->childContent($child),
                context: new HistoryMessageContext(
                    toolCallId: $this->findToolCallIdForChild($parent->id, $childId),
                    toolName: 'handover',
                ),
            );
        }

        $data = $parent->data ?? [];
        unset($data['spawned_sub_task_ids']);
        $data['sub_agent_resume'] = ['resumed_at' => date(Orchestrator::DB_TIMESTAMP_FORMAT)];

        $newStatus = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';

        Capsule::table('tasks')
            ->where('id', $parent->id)
            ->update([
                'status' => $newStatus,
                'data'   => json_encode($data, JSON_THROW_ON_ERROR),
            ]);

        $this->publishParentState($parent->id, $parent->user_id);

        if ($this->workerMode === WorkerMode::Sync) {
            $orchestrator->tick($parent->id);
        }
    }

    /**
     * The parent task's tool_calls row for the `sub_agent` op that
     * spawned this child carries the mapping in `result_data.spawned_sub_task_id`.
     * Restore the provider_call_id so the next LLM round-trip sees the
     * tool result correlated with the originating tool call.
     */
    private function findToolCallIdForChild(int $parentTaskId, int $childId): ?string
    {
        $row = ToolCallModel::where('task_id', $parentTaskId)
            ->where('tool_name', 'handover')
            ->where('operation', 'sub_agent')
            ->whereRaw("json_extract(result_data, '$.spawned_sub_task_id') = ?", [$childId])
            ->first();

        return $row?->provider_call_id;
    }

    private function childContent(Task $child): string
    {
        if ($child->status === 'FAILED') {
            $reason = $child->failure_reason ?? $child->error_message ?? 'Sub-agent failed.';
            return "Sub-agent task #{$child->id} failed: " . Utf8Sanitizer::scrubString($reason);
        }

        $response = (string) ($child->final_response ?? '');

        return "Sub-agent task #{$child->id} completed:\n\n" . Utf8Sanitizer::scrubString($response);
    }

    /**
     * @return list<int>
     */
    private function extractSpawnedChildIds(Task $parent): array
    {
        $ids = $parent->data['spawned_sub_task_ids'] ?? [];
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map(static fn($id) => (int) $id, $ids));
    }

    private function publishParentState(int $parentTaskId, int $userId): void
    {
        if ($this->mercure === null) {
            return;
        }

        $task = Task::find($parentTaskId);
        if ($task === null) {
            return;
        }

        $resource = [
            'id'             => $task->id,
            'status'         => $task->status,
            'step_count'     => $task->step_count,
            'data'           => $task->data,
            'parent_task_id' => $task->parent_task_id,
        ];

        $this->mercure->publish($task->id, $userId, $resource);
    }
}
