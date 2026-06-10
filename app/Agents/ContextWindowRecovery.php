<?php

declare(strict_types=1);

namespace Spora\Agents;

use Psr\Log\LoggerInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Services\NotificationService;
use Throwable;

/**
 * Recovers from context-window errors by compacting the task history and
 * re-ticking.
 *
 * Extracted from {@see Orchestrator} so the orchestrator stays under the
 * SonarQube `php:S1448` method-count cap. Holds the orchestrator by
 * reference to call back into `tick()` after compaction (mirrors
 * {@see ToolCallExecutor}).
 */
final class ContextWindowRecovery
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?NotificationService $notificationService = null,
    ) {}

    public function tryCompactionAndRetry(?Task $task, ?Agent $agent, Throwable $originalError): void
    {
        if ($task === null || $agent === null) {
            throw $originalError;
        }

        $taskId = $task->id;

        $historyCount = TaskHistory::where('task_id', $taskId)->count();
        if ($historyCount <= 1) {
            $actualLimit = $this->orchestrator->errorClassifier()->extractActualContextWindow($originalError);
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

        $llmConfig = $this->orchestrator->llmConfigResolver()->resolveLlmConfig($agent);
        $maxTokensOutput = $llmConfig['max_tokens_output'];
        $temperature = $llmConfig['temperature'];

        $this->logger?->info('Context window error, compacting history and retrying', ['task_id' => $taskId]);

        try {
            $this->compactHistory($taskId, $maxTokensOutput, $temperature, $agent);
        } catch (Throwable $e) {
            $this->logger?->warning('Compaction failed', ['task_id' => $taskId, 'exception' => $e->getMessage()]);
            throw $originalError;
        }

        try {
            $this->orchestrator->tick($taskId);
        } catch (Throwable) {
            throw $originalError;
        }
    }

    private function compactHistory(int $taskId, int $maxTokensOutput, float $temperature, Agent $agent): void
    {
        $allRows = TaskHistory::where('task_id', $taskId)
            ->orderBy('sequence')
            ->get();

        $keepCount = 5;
        if ($allRows->count() <= $keepCount + 1) {
            return;
        }

        $toSummarizeRows = $allRows->take($allRows->count() - $keepCount);

        $firstRow = $toSummarizeRows->first();
        $lastRow = $toSummarizeRows->last();
        $firstSeq = $firstRow !== null ? $firstRow->sequence : 0;
        $lastSeq = $lastRow !== null ? $lastRow->sequence : 0;

        $summaryMessages = [];
        foreach ($toSummarizeRows as $row) {
            $content = $row->content ?? '';
            if ($row->role === 'tool') {
                $content = "[{$row->tool_name}]: " . $content;
            }
            if ($content !== '') {
                $summaryMessages[] = ['role' => $row->role, 'content' => $content];
            }
        }

        if ($summaryMessages === []) {
            \Illuminate\Database\Capsule\Manager::connection()->transaction(function () use ($taskId, $firstSeq, $lastSeq) {
                TaskHistory::where('task_id', $taskId)
                    ->where('sequence', '>=', $firstSeq)
                    ->where('sequence', '<=', $lastSeq)
                    ->delete();
            });
            return;
        }

        $systemPrompt = ($agent->system_prompt !== null && $agent->system_prompt !== '')
            ? $agent->system_prompt
            : 'You are a helpful AI assistant.';

        $summaryInstruction = 'Summarize the conversation below concisely, preserving key facts, decisions, and any pending tasks. Output only the summary.';

        $summaryRequest = new LLMRequest(
            systemPrompt: $systemPrompt,
            messages: array_merge($summaryMessages, [['role' => 'user', 'content' => $summaryInstruction]]),
            tools: [],
            maxTokens: min($maxTokensOutput, 1024),
            temperature: $temperature,
        );

        $driver = $this->orchestrator->getDriverFactory()->makeFromAgent($agent);
        $response = $driver->complete($summaryRequest);

        $summaryText = $response->content ?? 'Conversation summarized.';

        \Illuminate\Database\Capsule\Manager::connection()->transaction(function () use ($taskId, $firstSeq, $lastSeq, $summaryText) {
            TaskHistory::where('task_id', $taskId)
                ->where('sequence', '>=', $firstSeq)
                ->where('sequence', '<=', $lastSeq)
                ->delete();

            TaskHistory::create([
                'task_id' => $taskId,
                'sequence' => $firstSeq,
                'role' => 'summary',
                'content' => $summaryText,
                'summarized_sequence_range' => "{$firstSeq}-{$lastSeq}",
            ]);

            $remaining = TaskHistory::where('task_id', $taskId)
                ->where('sequence', '>', $lastSeq)
                ->orderBy('sequence')
                ->get();

            $nextSeq = $lastSeq + 1;
            foreach ($remaining as $row) {
                $row->sequence = $nextSeq;
                $row->save();
                $nextSeq++;
            }
        });
    }
}
