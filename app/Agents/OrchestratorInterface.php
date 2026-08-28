<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Models\Task;

/**
 * Contract for the agent orchestration loop.
 *
 * Implementations drive the tick-based execution of agent tasks,
 * handling tool calls, human approval, retries, and task continuation.
 */
interface OrchestratorInterface
{
    /**
     * @param  int     $agentId
     * @param  string  $userPrompt   The user's initial instruction.
     * @param  int     $maxSteps     Hard iteration cap. Copied to Task at creation.
     * @param  int|null $parentTaskId Optional parent task for follow-up chaining.
     * @param  int|null $runId       Optional scheduled run ID for tracking.
     * @param  int|null $userId      Optional explicit caller id for task
     *                                attribution (`tasks.user_id`). When
     *                                omitted, the orchestrator falls back
     *                                to the agent's runner (used by worker
     *                                and scheduled-run paths that legitimately
     *                                run as the most-recent credential owner).
     * @return Task                  The newly created Task (status: RUNNING).
     */
    public function start(int $agentId, string $userPrompt, int $maxSteps = 10, ?int $parentTaskId = null, ?int $runId = null, array $mediaIds = [], ?int $userId = null): Task;

    /**
     * One iteration of the loop. Called by the Symfony Messenger handler.
     *
     * `$config` is the lease-aware config used by client/server-mode ticks
     * (browser SharedWorker, scheduled-run housekeeping). When supplied,
     * the orchestrator threads the lease owner through `TickPhaseRunner`
     * so the reaper cannot flip a still-progressing task to FAILED.
     * When omitted, the orchestrator uses its stored `currentTickConfig`
     * (set by a parent tick), or no lease at all (the messenger daemon's
     * default path — no lease needed because the reaper is gated on
     * `lease_expires_at IS NULL` in addition to `updated_at`).
     */
    public function tick(int $taskId, ?OrchestratorConfig $config = null): void;

    /**
     * Apply per-call approval or rejection decisions to a task paused for human review.
     *
     * Approved calls execute with the confirmed arguments. Rejected calls are recorded
     * in tool history so the model can choose an alternative action.
     *
     * @param  list<array{provider_call_id: string, decision: 'approve'|'reject', arguments?: array<string, mixed>, reason?: string}>  $decisions
     */
    public function resume(int $taskId, array $decisions): void;

    /**
     * @param  int    $taskId
     * @param  string $reason  Surfaced to the LLM so it can choose an alternative action.
     */
    public function reject(int $taskId, string $reason): void;

    /**
     * Continue a task from one of {COMPLETED, FAILED, ABORTED, RUNNING}.
     * The RUNNING branch auto-flips to ABORTED, appends the marker row
     * + user prompt, and returns without ticking — the next continue
     * (on the now-ABORTED row) drives the LLM. ABORTED sources resume
     * and wipe `data.aborted_at`. All branches reset retry-chain markers
     * (`retry_of_task_id`, `retry_after`, `error_code`, `error_message`,
     * `failure_reason`) so a stale auto-retry row becomes claimable again.
     *
     * @param  int      $taskId
     * @param  string   $newPrompt
     * @param  int|null $additionalSteps  Override max_steps for this continuation.
     * @return Task
     */
    public function continue(int $taskId, string $newPrompt, ?int $additionalSteps = null, array $mediaIds = []): Task;

    /**
     * Append a `task_history` row. Used by extracted services (e.g.
     * SubAgentService) that pre-existed the interface but need to write
     * back into the orchestrator's history stream.
     */
    public function appendHistory(
        int $taskId,
        string $role,
        ?string $content,
        ?HistoryMessageContext $context = null,
    ): void;

    /**
     * Re-run a failed task in place. Same task_id, full history preserved
     * as LLM context; resets status, step_count, and error fields.
     */
    public function retry(int $taskId): Task;

    /**
     * Abort the running agent loop for `$taskId`. Flips status to
     * `ABORTED`, persists `data.aborted_at`, and records the new state in
     * the task's history by leaving the most recent tool/assistant row
     * intact (the next resume appends a fresh marker + user prompt on top
     * via {@see continue()}). Idempotent — calling twice on the same
     * task is a no-op that returns the current row.
     *
     * Throws {@see Exceptions\InvalidTaskTransitionException}
     * when the task is in a state that doesn't allow aborting (terminal,
     * or already in a paused state with its own affordances).
     *
     * The caller is responsible for the user-ownership check; the
     * orchestrator only enforces the state-machine invariant.
     */
    public function abort(int $taskId): Task;

}
