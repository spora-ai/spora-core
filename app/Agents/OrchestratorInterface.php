<?php

declare(strict_types=1);

namespace Spora\Agents;

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
     * Execute the batch of tool calls that were paused for human approval.
     *
     * @param  int   $taskId
     * @param  list<array{provider_call_id: string, arguments: array<string, mixed>}>  $approvedBatch
     *               One entry per pending tool call. Each entry carries the provider_call_id
     *               (to correlate with pending_state) and the arguments confirmed (or edited) by the human.
     *               Arguments are validated against the tool's JSON Schema before execution.
     */
    public function resume(int $taskId, array $approvedBatch): void;

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
}
