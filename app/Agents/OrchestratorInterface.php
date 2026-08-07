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
     * @return Task                  The newly created Task (status: RUNNING).
     */
    public function start(int $agentId, string $userPrompt, int $maxSteps = 10, ?int $parentTaskId = null, ?int $runId = null, array $mediaIds = []): Task;

    /**
     * One iteration of the loop. Called by the Symfony Messenger handler.
     */
    public function tick(int $taskId): void;

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
     * Continue a completed or failed task with a new prompt.
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

}
