<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Agents\Exceptions\InvalidTaskTransitionException;
use Spora\Agents\Exceptions\TaskStateMissingException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\ScrubDataUrls;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Executes the batch of tool calls that were paused for human approval.
 * Holds the orchestrator by ref to call back into `resolveToolByName`,
 * `safeExecute`, `appendHistory`, and `tick` (mirrors {@see ToolCallExecutor}).
 */
final class ApprovedBatchExecutor
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly WorkerMode $workerMode,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(int $taskId, array $approvedBatch): void
    {
        [$task, $state] = $this->loadTaskAndStateForResume($taskId);

        try {
            $this->logger?->info('Task resumed after approval', [
                'task_id' => $task->id,
                'approved_count' => count($approvedBatch),
            ]);

            $approvedMap = $this->indexApprovedBatch($approvedBatch);

            foreach ($state->pendingToolCalls as $pendingToolCall) {
                $this->executeApprovedToolCall($pendingToolCall, $approvedMap, $task, $state, $taskId);
            }

            $this->cleanupStrandedApprovals($task, $taskId);
            $this->completeResume($taskId);
        } catch (Throwable $e) {
            $this->markResumeFailed($taskId, $e);
            throw $e;
        }
    }

    /**
     * @return array{0: Task, 1: AgentState}
     */
    private function loadTaskAndStateForResume(int $taskId): array
    {
        $task = null;
        $state = null;

        Capsule::connection()->transaction(function () use ($taskId, &$task, &$state) {
            /** @var Task $task */
            $task = Task::where('id', $taskId)->lockForUpdate()->firstOrFail();

            if ($task->status !== 'PENDING_APPROVAL') {
                throw new InvalidTaskTransitionException("Task {$taskId} is not awaiting approval.");
            }

            $state = $task->pending_state === null
                ? $this->emptyAgentStateFor($task)
                : AgentState::fromJson($task->pending_state);

            $task->pending_state = null;
            $task->save();
        });

        if (!$task instanceof Task || !$state instanceof AgentState) {
            throw new TaskStateMissingException('Failed to resolve task or state during resume.');
        }

        return [$task, $state];
    }

    private function emptyAgentStateFor(Task $task): AgentState
    {
        return new AgentState(
            taskId: $task->id,
            agentId: $task->agent_id,
            pendingToolCalls: [],
            messageSnapshot: [],
            stepCount: $task->step_count,
            maxSteps: $task->max_steps,
            pausedAt: date('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * @return array<string, array>
     */
    private function indexApprovedBatch(array $approvedBatch): array
    {
        $approvedMap = [];
        foreach ($approvedBatch as $item) {
            $approvedMap[$item['provider_call_id']] = $item['arguments'];
        }
        return $approvedMap;
    }

    private function executeApprovedToolCall(
        DriverToolCall $pendingToolCall,
        array $approvedMap,
        Task $task,
        AgentState $state,
        int $taskId,
    ): void {
        $approvedArgs = $approvedMap[$pendingToolCall->providerCallId] ?? $pendingToolCall->arguments;
        $toolInstance = $this->orchestrator->resolveToolByName($pendingToolCall->toolName);

        try {
            SchemaValidator::validate($approvedArgs, $toolInstance->getParametersSchema());
        } catch (Throwable $e) {
            $this->recordResumeValidationFailure($task, $taskId, $pendingToolCall, $e);
            return;
        }

        $result = $this->orchestrator->safeExecute($toolInstance, $approvedArgs, $state->agentId, $taskId, $task->user_id);
        $this->recordResumeExecutionResult($task, $taskId, $pendingToolCall, $approvedArgs, $result);
    }

    private function recordResumeValidationFailure(
        Task $task,
        int $taskId,
        DriverToolCall $pendingToolCall,
        Throwable $e,
    ): void {
        $result = new ToolResult(false, 'Validation Error: ' . $e->getMessage());

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub($result->content),
            context: new HistoryMessageContext(
                toolCallId: $pendingToolCall->providerCallId,
                toolName: $pendingToolCall->toolName,
            ),
        );

        ToolCallModel::where('task_id', $taskId)
            ->where('provider_call_id', $pendingToolCall->providerCallId)
            ->update([
                'status'         => 'APPROVED',
                'result_content' => ScrubDataUrls::scrub($result->content),
                'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);
    }

    private function recordResumeExecutionResult(
        Task $task,
        int $taskId,
        DriverToolCall $pendingToolCall,
        array $approvedArgs,
        ToolResult $result,
    ): void {
        ToolCallModel::where('task_id', $taskId)
            ->where('provider_call_id', $pendingToolCall->providerCallId)
            ->update([
                'status'             => 'APPROVED',
                'approved_arguments' => json_encode($approvedArgs, JSON_THROW_ON_ERROR),
                'result_content'     => ScrubDataUrls::scrub($result->content),
                'result_data'        => $result->data ? json_encode($result->data, JSON_THROW_ON_ERROR) : null,
                'executed_at'        => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);

        // Append the tool result into history so the LLM sees it on the next tick.
        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub($result->content),
            context: new HistoryMessageContext(
                toolCallId: $pendingToolCall->providerCallId,
                toolName: $pendingToolCall->toolName,
            ),
        );
    }

    private function cleanupStrandedApprovals(Task $task, int $taskId): void
    {
        // Clean up any stranded PENDING_APPROVAL records from concurrency bugs.
        $danglingTools = ToolCallModel::where('task_id', $taskId)
            ->where('status', 'PENDING_APPROVAL')
            ->get();

        foreach ($danglingTools as $danglingTool) {
            $this->orchestrator->appendHistory(
                taskId: $task->id,
                role: 'tool',
                content: 'Action discarded (state mismatch/timeout)',
                context: new HistoryMessageContext(
                    toolCallId: $danglingTool->provider_call_id,
                    toolName: $danglingTool->tool_name,
                ),
            );
        }

        ToolCallModel::where('task_id', $taskId)
            ->where('status', 'PENDING_APPROVAL')
            ->update(['status' => 'REJECTED']);
    }

    private function completeResume(int $taskId): void
    {
        $taskStatus = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        Task::where('id', $taskId)->update(['status' => $taskStatus]);

        if ($this->workerMode === WorkerMode::Sync) {
            // Tick is called after the transaction commits so the LLM round-trip
            // does not hold the lockForUpdate open for its full duration.
            $this->orchestrator->tick($taskId);
        }
    }

    private function markResumeFailed(int $taskId, Throwable $e): void
    {
        Task::where('id', $taskId)->update([
            'status'         => 'FAILED',
            'error_code'     => 'RESUME_FAILED',
            'error_message'  => 'Task resume failed: ' . $e->getMessage(),
            'failure_reason' => $e->getMessage(),
        ]);
    }
}
