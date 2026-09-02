<?php

declare(strict_types=1);

namespace Spora\Services;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\Text\Utf8Sanitizer;
use Throwable;

/**
 * Spawns child tasks from a `HandoverTool` `sub_agent` invocation and
 * resumes the parent once every child reaches a terminal state.
 *
 * Authorization mirrors {@see HandoverService::handover()}: the caller
 * must own both the parent task and the target agent.
 *
 * Multi-child batch invariant (e.g. `[sub_agent, calculator, sub_agent]`):
 * the parent resumes ONCE at the batch boundary, not after every child.
 * Each spawn increments `data.sub_agent_expected_count` after the child
 * tick; the resume gate compares the count against the spawned ids and
 * only fires when both match — mid-batch hits see expected < terminal
 * and refuse to resume. The actual resume is driven by the batch-boundary
 * hooks in {@see \Spora\Agents\ApprovedBatchExecutor::execute()} (sync)
 * and {@see \Spora\Agents\TickPhaseRunner::executeApprovedPendingToolsForTask()}
 * (worker), which call {@see maybeResumeParentForParent()}.
 */
final class SubAgentService implements SubAgentServiceInterface
{
    /**
     * Allowed `data` keys when projecting a parent's `data` JSON column
     * into the Mercure live-stream payload. Anything else is dropped —
     * see {@see publishParentState()} below.
     *
     * @var list<string>
     */
    private const PARENT_STATE_DATA_ALLOWLIST = [
        'spawned_sub_task_ids',
        'sub_agent_expected_count',
        'run_id',
    ];

    /**
     * @param Closure(): OrchestratorInterface $orchestratorFactory
     *   Lazy factory — the Orchestrator's constructor takes the tool
     *   instance list (which includes `HandoverTool`), so direct injection
     *   creates a circular dependency. Mirrors the pattern in {@see HandoverService}.
     */
    public function __construct(
        private readonly Closure $orchestratorFactory,
        private readonly ?MercurePublisherInterface $mercure = null,
        ?PrincipalService $principalService = null,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->principalService = $principalService ?? new PrincipalService(new PrincipalResolver());
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    private readonly PrincipalService $principalService;
    private readonly PrincipalResolver $principalResolver;

    public function spawn(int $parentTaskId, int $targetAgentId, string $prompt, int $userId): Task
    {
        // Parent-task gate widens to principal_id membership: any group
        // member can spawn a sub-agent on a parent's conversation.
        $visiblePrincipalIds = $this->principalResolver->visiblePrincipalIds($userId);
        $parent = Task::where('id', $parentTaskId)
            ->whereIn('principal_id', $visiblePrincipalIds)
            ->first();
        // Migration 0067: agents are owned by a principal. Look up the
        // target agent and verify the caller controls its principal.
        $targetAgent = Agent::find($targetAgentId);
        if ($parent === null || $targetAgent === null) {
            throw new InvalidArgumentException('Parent task or target agent not found.');
        }
        if (!$this->principalService->callerControlsPrincipal($userId, (int) $targetAgent->principal_id)) {
            throw new InvalidArgumentException('Parent task or target agent not found.');
        }

        // Flip to AWAITING_SUB_AGENTS before the child tick — in Sync mode
        // the child ticks inline and may complete before we return, and the
        // parent's status is what gates the resume path on child completion.
        // First-spawn-of-batch also opens the batch-open flag so the
        // per-child resume hook refuses to fire mid-batch (the fix for the
        // multi-child race where a concurrent child worker cleared the
        // parent's data and the spawn sequence wrote `expected=N-1` for N
        // children — see maybeResumeParent's batch-open gate below).
        $this->markParentAwaitingSubAgents($parent);

        // Catch driver failures from the child tick: TickPhaseRunner marks
        // the child FAILED before the exception propagates, so the row exists
        // and we can return it. Letting the throw bubble would mark the
        // PARENT FAILED via ApprovedBatchExecutor::markResumeFailed, which
        // is wrong — the parent should resume with a failure tool result so
        // the LLM can react.
        try {
            $child = ($this->orchestratorFactory)()->start(
                agentId: $targetAgent->id,
                userPrompt: $prompt,
                maxSteps: (int) ($targetAgent->max_steps ?? 10),
                parentTaskId: $parent->id,
                userId: $userId,
            );
        } catch (Throwable $e) {
            $recovered = Task::where('parent_task_id', $parent->id)
                ->orderByDesc('id')
                ->first();
            if ($recovered === null) {
                throw $e;
            }
            $child = $recovered;
        }

        $this->recordSpawnedChild($parent, $child->id);

        // Post-tick re-check is a no-op once the batch-open flag is set
        // (the per-child hook is gated by it); the actual resume fires from
        // the batch-boundary hook after all spawns have incremented and the
        // flag is cleared.
        $child->refresh();
        if (in_array($child->status, ['COMPLETED', 'FAILED'], true)) {
            $this->maybeResumeParent($child->id);
        }

        // Increment AFTER the post-tick re-check so any mid-batch check
        // sees expected from the PREVIOUS spawn, not this one.
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

        // Cross-process safety net: if a concurrent child worker completed
        // its tick mid-batch (before the parent recorded the spawn), the
        // per-child hook would otherwise fire `resumeParent` against a parent
        // whose `data` hasn't been incremented yet — the resume gate would
        // then permanently fail because `count(spawned) !== expected`.
        // The batch-boundary hook (maybeResumeParentForParent) clears the
        // flag after the parent has recorded every spawn, so the next
        // per-child event (e.g. a child re-ticked from QUEUED) flows through.
        if (($ready['parent']->data['sub_agent_batch_open'] ?? false) === true) {
            return;
        }

        $this->resumeParent($ready['parent'], $ready['siblingIds']);
    }

    /**
     * Batch-boundary hook — fires from ApprovedBatchExecutor (sync) and
     * TickPhaseRunner (worker) after every approved tool in a batch has
     * run. Resumes the parent iff the spawned-id count matches
     * `sub_agent_expected_count` and every sibling is terminal.
     *
     * The batch-open flag is cleared BEFORE the resume decision so the next
     * per-child event (e.g. a sibling re-ticked from QUEUED in worker mode)
     * can flow through `maybeResumeParent` instead of being short-circuited
     * by the gate. The flag is the cross-process lock that prevents the
     * race where a fast child worker resumes the parent before the parent
     * has recorded all of its spawns.
     */
    public function maybeResumeParentForParent(int $parentTaskId): void
    {
        $parent = Task::find($parentTaskId);
        if ($parent === null || $parent->status !== 'AWAITING_SUB_AGENTS') {
            return;
        }

        $this->closeBatch($parent);

        $expectedCount = (int) ($parent->data['sub_agent_expected_count'] ?? 0);
        $siblingIds = $expectedCount > 0 ? $this->extractSpawnedChildIds($parent) : [];
        if (
            $expectedCount <= 0
            || count($siblingIds) !== $expectedCount
            || !$this->allSiblingsTerminal($siblingIds)
        ) {
            return;
        }

        $this->resumeParent($parent, $siblingIds);
    }

    /**
     * Resolves the parent + sibling ids when every precondition holds; null
     * otherwise. Preconditions: child is terminal, parent is awaiting
     * sub-agents, and the spawned-id count matches `sub_agent_expected_count`
     * (the multi-child gate — mid-batch hits always return null).
     *
     * @return array{parent: Task, siblingIds: list<int>}|null
     */
    private function loadReadyParent(int $childTaskId): ?array
    {
        $child = Task::find($childTaskId);
        $parent = $child !== null && $child->parent_task_id !== null
            ? Task::find($child->parent_task_id)
            : null;
        if ($parent === null || $parent->status !== 'AWAITING_SUB_AGENTS') {
            return null;
        }

        $siblingIds = $this->extractSpawnedChildIds($parent);
        if (!$this->isBatchReadyToResume($parent, $siblingIds, $child)) {
            return null;
        }

        return ['parent' => $parent, 'siblingIds' => $siblingIds];
    }

    /**
     * Multi-child gate: true only when the spawned-id count matches
     * `sub_agent_expected_count`, the child just completed is terminal, and
     * every sibling has reached a terminal state.
     */
    private function isBatchReadyToResume(Task $parent, array $siblingIds, Task $child): bool
    {
        $expectedCount = (int) ($parent->data['sub_agent_expected_count'] ?? 0);

        return $expectedCount > 0
            && count($siblingIds) === $expectedCount
            && in_array($child->status, ['COMPLETED', 'FAILED'], true)
            && $this->allSiblingsTerminal($siblingIds);
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
     * Read-modify-write (not a raw `+1` SQL update) so the increment and
     * `data.spawned_sub_task_ids` stay in lockstep if a concurrent writer
     * touched the row between {@see recordSpawnedChild()} and this call.
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
     * Seeds `data.spawned_sub_task_ids = []`, opens the batch-open flag, and
     * flips the parent to `AWAITING_SUB_AGENTS`. Called BEFORE the child tick
     * so the child's `maybeResumeParent` hook sees the parent in the right
     * state.
     *
     * Short-circuits when the parent is already awaiting sub-agents with a
     * seeded `spawned_sub_task_ids` array — multi-child batches keep the
     * parent in this state across spawns, so a second `spawn()` would
     * otherwise rewrite the same row. The flag stays open across the batch
     * and is cleared by {@see closeBatch()} from the batch-boundary hook.
     */
    private function markParentAwaitingSubAgents(Task $parent): void
    {
        $data = $parent->data ?? [];
        $alreadySeeded = ($parent->status === 'AWAITING_SUB_AGENTS')
            && array_key_exists('spawned_sub_task_ids', $data)
            && is_array($data['spawned_sub_task_ids']);

        if ($alreadySeeded) {
            return;
        }

        $data['spawned_sub_task_ids'] = $data['spawned_sub_task_ids'] ?? [];
        $data['sub_agent_batch_open'] = true;

        Capsule::table('tasks')
            ->where('id', $parent->id)
            ->update([
                'status' => 'AWAITING_SUB_AGENTS',
                'data'   => json_encode($data, JSON_THROW_ON_ERROR),
            ]);
    }

    /**
     * Cross-process lock against the multi-child stall. Cleared by the
     * batch-boundary hook (maybeResumeParentForParent) so a subsequent
     * per-child event from a slow worker can resume normally. If the gate
     * fails (count mismatch, non-terminal sibling), the flag stays open —
     * the next batch-boundary call will clear it and re-evaluate.
     */
    private function closeBatch(Task $parent): void
    {
        $data = $parent->data ?? [];
        if (($data['sub_agent_batch_open'] ?? false) !== true) {
            return;
        }

        unset($data['sub_agent_batch_open']);

        Capsule::table('tasks')
            ->where('id', $parent->id)
            ->update(['data' => json_encode($data, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Replaces the immediate `role:'tool'` history row from the originating
     * `sub_agent` call with the eventual child output, clears the spawned
     * book-keeping, flips the parent to RUNNING (Sync) or QUEUED (Worker),
     * and kicks the next tick.
     *
     * The immediate row was written by {@see ToolCallExecutor::executeAndRecordResult()}
     * (auto-approved/sync) or {@see ApprovedBatchExecutor::recordResumeExecutionResult()}
     * (approval-required/sync) or by the worker-mode equivalent. Replacing
     * keeps exactly one `role:'tool'` row per `provider_call_id` so the
     * next LLM round-trip sees a single correlated result.
     *
     * Idempotent under concurrent invocation: the per-child hook and the
     * batch-boundary hook can race in worker mode (a slow sibling re-tick
     * may fire `maybeResumeParent` after the boundary hook already flipped
     * the row). The lockForUpdate + status guard collapses the race to a
     * single status flip and skips duplicate tool-row writes.
     *
     * @param list<int> $childIds
     */
    public function resumeParent(Task $parent, array $childIds): bool
    {
        return Capsule::connection()->transaction(function () use ($parent, $childIds): bool {
            $row = Capsule::table('tasks')
                ->where('id', $parent->id)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return false;
            }
            // Idempotency: another caller (batch-boundary hook racing
            // per-child hook in worker mode) already flipped the row.
            if ($row->status !== 'AWAITING_SUB_AGENTS') {
                return false;
            }

            $orchestrator = ($this->orchestratorFactory)();

            foreach ($childIds as $childId) {
                $child = Task::find($childId);
                if ($child === null) {
                    continue;
                }

                $toolCallId = $this->findToolCallIdForChild((int) $parent->id, $childId);
                $this->replaceOrAppendToolHistory(
                    orchestrator: $orchestrator,
                    parentTaskId: (int) $parent->id,
                    toolCallId: $toolCallId,
                    content: $this->childContent($child),
                );
            }

            $data = $parent->data ?? [];
            unset($data['spawned_sub_task_ids']);
            unset($data['sub_agent_expected_count']);
            unset($data['sub_agent_batch_open']);

            Capsule::table('tasks')
                ->where('id', $parent->id)
                ->update([
                    'status' => 'QUEUED',
                    'data'   => json_encode($data, JSON_THROW_ON_ERROR),
                ]);

            $this->publishParentState((int) $parent->id, (int) $parent->principalUserId());

            return true;
        });
    }

    /**
     * Look up the `tool_calls` row that spawned this child and return
     * its `provider_call_id` so the next LLM round-trip sees the tool
     * result correlated with the originating call.
     *
     * Decoded in PHP rather than via SQL JSON functions so the rows
     * scope stays portable across SQLite and MySQL/MariaDB. The
     * `result_data` cast on the model decodes the JSON column on
     * read. The result set is bounded by the task's handover tool
     * calls — at most one row per tool call in the originating
     * turn — so the linear scan is cheap.
     */
    private function findToolCallIdForChild(int $parentTaskId, int $childId): ?string
    {
        $rows = ToolCallModel::where('task_id', $parentTaskId)
            ->where('tool_name', 'handover')
            ->where('operation', 'sub_agent')
            ->get();

        foreach ($rows as $row) {
            $spawned = $row->result_data['spawned_sub_task_ids'] ?? null;
            if (is_array($spawned) && in_array($childId, $spawned, true)) {
                return $row->provider_call_id;
            }
        }

        return null;
    }

    /**
     * Replace the existing `role:'tool'` history row correlated with
     * `$toolCallId` so the eventual child output supersedes the
     * immediate result written at tool-call time. Falls back to
     * appending a new row when no correlated row exists (orphan edge
     * case — defensive, the immediate write path normally precedes us).
     */
    private function replaceOrAppendToolHistory(
        OrchestratorInterface $orchestrator,
        int $parentTaskId,
        ?string $toolCallId,
        string $content,
    ): void {
        $existing = TaskHistory::where('task_id', $parentTaskId)
            ->where('role', 'tool')
            ->where('tool_call_id', $toolCallId)
            ->orderByDesc('sequence')
            ->first();

        if ($existing !== null) {
            $existing->update([
                'content'   => $content,
                'tool_name' => 'handover',
            ]);
            return;
        }

        $orchestrator->appendHistory(
            taskId: $parentTaskId,
            role: 'tool',
            content: $content,
            context: new HistoryMessageContext(
                toolCallId: $toolCallId,
                toolName: 'handover',
            ),
        );
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

        $rawData = is_array($task->data) ? $task->data : [];
        $projectedData = [];
        foreach (self::PARENT_STATE_DATA_ALLOWLIST as $key) {
            if (array_key_exists($key, $rawData)) {
                $projectedData[$key] = $rawData[$key];
            }
        }

        $resource = [
            'id'             => $task->id,
            'status'         => $task->status,
            'step_count'     => $task->step_count,
            'data'           => $projectedData,
            'parent_task_id' => $task->parent_task_id,
        ];

        $this->mercure->publish($task->id, $userId, $resource);
    }
}
