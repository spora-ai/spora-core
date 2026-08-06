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
use Throwable;

/**
 * Spawns child tasks from a `HandoverTool` `sub_agent` invocation and
 * resumes the parent once every child reaches a terminal state.
 *
 * Authorization mirrors {@see HandoverService::handover()}: the caller
 * must own both the parent task and the target agent. Cross-user
 * delegation is out of scope.
 *
 * The tool_call_id ↔ child_ids mapping is kept on the persisted
 * `tool_calls` row (`result_data.spawned_sub_task_ids`, an array) by
 * the tool itself, so the resume path reads it back as a single
 * SELECT — no extra plumbing through the orchestrator. The array
 * shape (always plural) keeps the schema uniform across single-child
 * and multi-child batches.
 *
 * Multi-child batch invariant (e.g. a single LLM turn that emits
 * `[sub_agent, calculator, sub_agent]`): the parent is paused while
 * each child runs, and resumes ONCE at the batch boundary, not after
 * every child. The mechanism is the `sub_agent_expected_count`
 * counter — every spawn increments it AFTER the child tick, so any
 * check that fires mid-batch sees `expected < terminal` and refuses
 * to resume. The actual resume fires from the batch-boundary hook
 * (ApprovedBatchExecutor in sync mode, TickPhaseRunner in worker
 * mode); see {@see \Spora\Agents\ApprovedBatchExecutor::execute()}
 * and {@see \Spora\Agents\TickPhaseRunner::executeApprovedPendingToolsForTask()}.
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

    public function spawn(int $parentTaskId, int $targetAgentId, string $prompt, int $userId): Task
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

        // Sync mode: the child ticks inline. If the child's LLM driver
        // throws, TickPhaseRunner::handleTickFailure already marked the
        // child task as FAILED before the exception propagated up — so we
        // catch it here, look up the child by parent_task_id (the only child
        // we just created), and return the FAILED row instead of letting
        // the resume path abort. Letting the throw bubble would mark the
        // PARENT task FAILED via ApprovedBatchExecutor::markResumeFailed,
        // which is wrong: the parent should still wake up with a failure
        // tool result so the LLM can choose how to react.
        try {
            $child = ($this->orchestratorFactory)()->start(
                agentId: $targetAgent->id,
                userPrompt: $prompt,
                maxSteps: (int) ($targetAgent->max_steps ?? 10),
                parentTaskId: $parent->id,
            );
        } catch (Throwable $e) {
            $child = Task::where('parent_task_id', $parent->id)
                ->orderByDesc('id')
                ->first();
            if ($child === null) {
                throw $e;
            }
        }

        $this->recordSpawnedChild($parent, $child->id);

        // Sync mode: the child has already ticked (and may have completed)
        // by the time we get here. The TickPhaseRunner hook fired inside the
        // child's tick, but at that moment the parent had no spawned ids
        // recorded yet, so loadReadyParent returned early. Re-check now
        // that the registration is committed — with the multi-child
        // invariant below, this is a no-op for batches (expected_count is
        // still 0, terminal_count is 1, no resume), and the actual resume
        // fires from the batch-boundary hook.
        $child->refresh();
        if (in_array($child->status, ['COMPLETED', 'FAILED'], true)) {
            $this->maybeResumeParent($child->id);
        }

        // Increment expected_count AFTER the post-tick re-check so any
        // mid-batch check (TickPhaseRunner hook, post-tick re-check) sees
        // expected from the PREVIOUS spawn, not this one. In a single-child
        // batch expected reaches terminal on the next child-completion hook;
        // in a multi-child batch expected reaches terminal only after the
        // last spawn in the batch has been recorded AND its child tick has
        // completed, at which point the batch-boundary hook in
        // ApprovedBatchExecutor (sync) or TickPhaseRunner (worker) fires
        // the resume exactly once.
        $this->incrementExpectedCount($parent->id);

        $this->publishParentState($parent->id, $userId);

        return $child;
    }

    public function maybeResumeParent(int $childTaskId): void
    {
        $ready = $this->loadReadyParent($childTaskId);
        if ($ready === null) {
            return;
        }

        $this->resumeParent($ready['parent'], $ready['siblingIds']);
    }

    /**
     * Batch-boundary hook for ApprovedBatchExecutor (sync mode) and
     * TickPhaseRunner (worker mode). Operates on a parent task id and
     * resolves the resume iff the parent's `sub_agent_expected_count`
     * equals the number of terminal children it has spawned — the
     * same gate {@see loadReadyParent()} enforces from the child side.
     *
     * Idempotent: a parent that isn't waiting for sub-agents (no
     * `AWAITING_SUB_AGENTS` status, or `sub_agent_expected_count=0`)
     * is a no-op.
     */
    public function maybeResumeParentForParent(int $parentTaskId): void
    {
        $parent = Task::find($parentTaskId);
        if ($parent === null || $parent->status !== 'AWAITING_SUB_AGENTS') {
            return;
        }

        $expectedCount = (int) ($parent->data['sub_agent_expected_count'] ?? 0);
        if ($expectedCount <= 0) {
            return;
        }

        $siblingIds = $this->extractSpawnedChildIds($parent);
        if (
            count($siblingIds) !== $expectedCount
            || !$this->allSiblingsTerminal($siblingIds)
        ) {
            return;
        }

        $this->resumeParent($parent, $siblingIds);
    }

    /**
     * Walk the parent + sibling state for a child task and return the
     * resolved parent + sibling ids when every precondition holds.
     *
     * Returns null when any precondition fails:
     *   - child is missing or has no parent
     *   - child is not in a terminal state (COMPLETED / FAILED)
     *   - parent is missing or no longer in AWAITING_SUB_AGENTS
     *   - the parent has no `sub_agent_expected_count` recorded (no spawns yet)
     *   - the number of terminal siblings doesn't match the expected count
     *     (mid-batch — more spawns in this batch are still in flight or not
     *     yet recorded)
     *
     * The shape is a tagged array (intentionally not a class — the
     * caller only needs two fields and a class would be premature).
     *
     * The `sub_agent_expected_count` gate is the multi-child invariant:
     * a batch `[sub_agent, calculator, sub_agent]` records 1 after the
     * first spawn (terminal=1) and 2 after the second (terminal=2). Only
     * the second hit fires the resume — the first one sees expected < terminal
     * and returns null.
     *
     * @return array{parent: Task, siblingIds: list<int>}|null
     */
    private function loadReadyParent(int $childTaskId): ?array
    {
        $child = Task::find($childTaskId);
        if (
            $child === null
            || $child->parent_task_id === null
            || !in_array($child->status, ['COMPLETED', 'FAILED'], true)
        ) {
            return null;
        }

        $parent = Task::find($child->parent_task_id);
        if ($parent === null || $parent->status !== 'AWAITING_SUB_AGENTS') {
            return null;
        }

        $expectedCount = (int) ($parent->data['sub_agent_expected_count'] ?? 0);
        if ($expectedCount <= 0) {
            return null;
        }

        $siblingIds = $this->extractSpawnedChildIds($parent);
        if (
            count($siblingIds) !== $expectedCount
            || !$this->allSiblingsTerminal($siblingIds)
        ) {
            return null;
        }

        return ['parent' => $parent, 'siblingIds' => $siblingIds];
    }

    /**
     * @param list<int> $siblingIds
     */
    private function allSiblingsTerminal(array $siblingIds): bool
    {
        foreach ($siblingIds as $siblingId) {
            $sibling = Task::find($siblingId);
            if ($sibling === null || !in_array($sibling->status, ['COMPLETED', 'FAILED'], true)) {
                return false;
            }
        }

        return true;
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
     * Bump the parent's `sub_agent_expected_count` so the resume gate sees
     * one more expected child than it did before this spawn returned.
     *
     * Read-modify-write (not a raw `+1` SQL update) so the increment and
     * the in-memory `$data['spawned_sub_task_ids']` stay in lockstep if
     * any concurrent writer touched the row between {@see recordSpawnedChild()}
     * and this call.
     */
    private function incrementExpectedCount(int $parentId): void
    {
        $row = Capsule::table('tasks')->where('id', $parentId)->value('data');
        $data = is_string($row) ? json_decode($row, true) : null;
        if (!is_array($data)) {
            $data = [];
        }

        $data['sub_agent_expected_count'] = (int) ($data['sub_agent_expected_count'] ?? 0) + 1;

        Capsule::table('tasks')
            ->where('id', $parentId)
            ->update(['data' => json_encode($data, JSON_THROW_ON_ERROR)]);
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
        unset($data['sub_agent_expected_count']);

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
     * spawned this child carries the mapping in
     * `result_data.spawned_sub_task_ids` (an array — even when a single
     * child is spawned, the field is a one-element list). Restore the
     * provider_call_id so the next LLM round-trip sees the tool result
     * correlated with the originating tool call.
     *
     * SQLite 3.38+ supports `EXISTS (SELECT 1 FROM json_each(...))`,
     * which is what spora-core's test suite uses. MySQL's
     * `JSON_CONTAINS` is the equivalent on the production path; both
     * are wired up in {@see \Spora\Tools\Schema\OperationSchemaFilter}
     * for the same reason (JSON-shape agnosticism).
     */
    private function findToolCallIdForChild(int $parentTaskId, int $childId): ?string
    {
        $row = ToolCallModel::where('task_id', $parentTaskId)
            ->where('tool_name', 'handover')
            ->where('operation', 'sub_agent')
            ->whereRaw(
                "EXISTS (SELECT 1 FROM json_each(result_data, '$.spawned_sub_task_ids') WHERE value = ?)",
                [$childId],
            )
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
