<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Task;

/**
 * Spawns child tasks from a `HandoverTool` `sub_agent` invocation and resumes
 * the parent once every child reaches a terminal state.
 *
 * Contract for the `sub_agent` op:
 *   - The parent task is NOT closed; it stays owned by the source agent.
 *   - The child task is a regular Task with `parent_task_id = $parentTaskId`.
 *   - `parent.data.spawned_sub_task_ids` is the live set of child ids the
 *     resume path walks when deciding whether the parent is ready to
 *     wake up. The parent itself flips to `AWAITING_SUB_AGENTS` for the
 *     duration so the chat UI stops showing it as active.
 *   - On terminal completion of every child, the parent is resumed —
 *     child outputs are appended as `role:'tool'` rows so the next LLM
 *     tick correlates them with the originating tool call.
 */
interface SubAgentServiceInterface
{
    /**
     * Validate ownership, spawn a child task on the target agent, mark the
     * parent as waiting for the child, and publish the Mercure state change
     * so the frontend picks up the new status and child id(s) live.
     *
     * The child id is persisted on the parent tool-call row under
     * `result_data.spawned_sub_task_ids` as a single-element array — the
     * same shape the LLM-facing schema advertises for multi-child
     * batches, so the frontend never has to special-case N=1.
     *
     * @return Task The newly created child task.
     */
    public function spawn(int $parentTaskId, int $targetAgentId, string $prompt, int $userId): Task;

    /**
     * Hook called by `TickPhaseRunner` whenever a child task transitions
     * to a terminal status. If the parent is still waiting and every
     * sibling child is also terminal, resumes the parent with each
     * child's output appended as a `role:'tool'` history row.
     */
    public function maybeResumeParent(int $childTaskId): void;
}
