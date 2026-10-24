<?php

declare(strict_types=1);

namespace Spora\Agents;

use Psr\Log\LoggerInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Services\NotificationService;
use Spora\Services\TaskCompactionServiceInterface;
use Throwable;

/**
 * Recovers from context-window errors by compacting the task history and
 * re-ticking. Holds the orchestrator by reference to call back into `tick()`
 * after compaction (mirrors {@see ToolCallExecutor}).
 */
final class ContextWindowRecovery
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly TaskCompactionServiceInterface $compactionService,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?NotificationService $notificationService = null,
    ) {}

    public function tryCompactionAndRetry(?Task $task, ?Agent $agent, Throwable $originalError): void
    {
        if ($task === null || $agent === null) {
            // The tick could not resolve the task or the agent it runs for.
            throw $originalError;
        }

        $taskId = $task->id;

        $historyCount = TaskHistory::where('task_id', $taskId)->count();
        if ($historyCount <= 1) {
            $actualLimit = $this->orchestrator->errorClassifier->extractActualContextWindow($originalError);
            $msg = $actualLimit !== null
                ? "Context window too small ({$actualLimit} tokens). The model cannot process this request even without any conversation history. Try a model with a larger context window (e.g., 128K+ tokens) or reduce the system prompt length."
                : "Context window too small for the current prompt. The model cannot process this request even without any conversation history. Try a model with a larger context window (e.g., 128K+ tokens) or reduce the system prompt length.";

            Task::where('id', $taskId)->where('status', 'RUNNING')->update([
                'status'         => 'FAILED',
                'failure_reason' => $originalError->getMessage(),
                'error_code'     => 'CONTEXT_WINDOW_FIRST_TURN',
                'error_message' => $msg,
            ]);

            $this->notificationService?->notifyTaskFailed(Task::find($taskId));
            throw $originalError;
        }

        $this->logger?->info('Context window error, compacting history and retrying', ['task_id' => $taskId]);

        try {
            $compacted = $this->compactionService->compact($taskId);
        } catch (Throwable $e) {
            $this->logger?->warning('Compaction failed', ['task_id' => $taskId, 'exception' => $e->getMessage()]);
            throw $originalError;
        }

        if (! $compacted) {
            // Nothing was summarised, so the history is unchanged and the
            // retry would fail with the same context-window error.
            $this->logger?->warning('Compaction wrote no summary, so the history is unchanged', ['task_id' => $taskId]);
            throw $originalError;
        }

        // In client-worker mode the recursive tick would run another
        // LLM turn inside the same HTTP `/tick` request, hiding the
        // compaction-and-retry event from the SPA. Skip the retry
        // here — the row is left RUNNING with a live lease; the
        // reaper flips it to FAILED once the lease expires so the
        // SPA surfaces a clean error rather than a silent stall.
        if ($this->orchestrator->currentTickConfig()?->singleStep === true) {
            throw $originalError;
        }

        try {
            $this->orchestrator->tick($taskId);
        } catch (Throwable) {
            throw $originalError;
        }
    }

}
