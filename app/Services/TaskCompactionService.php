<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Agents\ConversationCompactor;
use Spora\Agents\Exceptions\CompactionFailedException;
use Spora\Agents\LlmConfigResolver;
use Spora\Models\Agent;
use Spora\Models\Task;

/**
 * Owns the resolution a compaction needs — the task, its agent, and the
 * agent's effective LLM configuration — and hands them to
 * {@see ConversationCompactor}.
 *
 * The resolution lives here so the manual endpoint and the context-window
 * recovery compact a task the same way: one place decides which agent
 * speaks for a task and with which budget.
 */
final class TaskCompactionService implements TaskCompactionServiceInterface
{
    public function __construct(
        private readonly ConversationCompactor $compactor,
        private readonly LlmConfigResolver $llmConfigResolver,
    ) {}

    /**
     * @throws CompactionFailedException  When the model returned no summary;
     *                                    the history is left unchanged.
     */
    public function compact(int $taskId): bool
    {
        $task = Task::find($taskId);
        if ($task === null) {
            return false;
        }

        $agent = Agent::find($task->agent_id);
        if ($agent === null) {
            return false;
        }

        $llmConfig = $this->llmConfigResolver->resolveLlmConfig($agent);

        return $this->compactor->compact(
            $taskId,
            $agent,
            $llmConfig['max_tokens_output'],
            $llmConfig['temperature'],
        );
    }
}
