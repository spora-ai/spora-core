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
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Resumes a task paused for human approval.
 *
 * Worker-mode contracts:
 *   - Sync: approved tools run inline.
 *   - Worker: the approval is persisted with `executed_at=NULL`; the daemon's
 *     next `runTick()` runs the tool (so long-running tools don't block the
 *     HTTP response).
 *
 * Partial approval: tool calls in `pending_state` that the user did NOT
 * include in the approval batch stay PENDING_APPROVAL — never silently
 * executed with the LLM's original arguments.
 */
final class ApprovedBatchExecutor
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly WorkerMode $workerMode,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param list<array{provider_call_id: string, arguments: array<string, mixed>}> $approvedBatch
     */
    public function execute(int $taskId, array $approvedBatch): void
    {
        [$task, $state] = $this->loadTaskAndStateForResume($taskId);

        try {
            $this->logger?->info('Task resumed after approval', [
                'task_id'        => $task->id,
                'approved_count' => count($approvedBatch),
                'pending_count'  => count($state->pendingToolCalls),
                'worker_mode'    => $this->workerMode === WorkerMode::Sync ? 'sync' : 'worker',
            ]);

            $approvedMap  = $this->indexApprovedBatch($approvedBatch);
            // Operation-per-call narrows per-op `required[]` against the op the call was
            // actually dispatched on — see ToolCallExecutor::createPendingRecord.
            $operationMap = $this->indexPersistedOperations($taskId);

            /** @var list<DriverToolCall> $remaining */
            $remaining = [];

            foreach ($state->pendingToolCalls as $pendingToolCall) {
                $operationName = $operationMap[$pendingToolCall->providerCallId] ?? null;
                $approvedArgs  = $approvedMap[$pendingToolCall->providerCallId] ?? null;

                // Partial-approval guard: absent from the batch = not approved, stays pending.
                if ($approvedArgs === null) {
                    $remaining[] = $pendingToolCall;
                    continue;
                }

                if ($this->workerMode === WorkerMode::Sync) {
                    $this->executeOneApprovedToolCall($pendingToolCall, $approvedArgs, $task, $state, $taskId, $operationName);
                } else {
                    $this->recordApprovalOnly($taskId, $pendingToolCall->providerCallId, $approvedArgs);
                }
            }

            if ($remaining !== []) {
                $this->reopenForRemainingPending($task, $state, $remaining);
                return;
            }

            // Any PENDING_APPROVAL rows left in the DB here are dangling (in DB but not
            // in saved state) — a state/DB-drift case, distinct from partial approval.
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
        $task  = null;
        $state = null;

        Capsule::connection()->transaction(function () use ($taskId, &$task, &$state): void {
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
     * @param list<array{provider_call_id: string, arguments: array<string, mixed>}> $approvedBatch
     * @return array<string, array<string, mixed>>
     */
    private function indexApprovedBatch(array $approvedBatch): array
    {
        $approvedMap = [];
        foreach ($approvedBatch as $item) {
            $approvedMap[$item['provider_call_id']] = $item['arguments'];
        }
        return $approvedMap;
    }

    /**
     * @return array<string, string>
     */
    private function indexPersistedOperations(int $taskId): array
    {
        $rows = ToolCallModel::where('task_id', $taskId)
            ->whereIn('status', ['PENDING_APPROVAL', 'AWAITING_FINAL_APPROVAL'])
            ->get(['provider_call_id', 'operation']);

        $map = [];
        foreach ($rows as $row) {
            $op = $row->getAttribute('operation');
            if (is_string($op) && $op !== '') {
                $map[$row->getAttribute('provider_call_id')] = $op;
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $approvedArgs
     */
    private function executeOneApprovedToolCall(
        DriverToolCall $pendingToolCall,
        array $approvedArgs,
        Task $task,
        AgentState $state,
        int $taskId,
        ?string $operationName,
    ): void {
        $toolInstance = $this->orchestrator->resolveToolByName($pendingToolCall->toolName);

        try {
            SchemaValidator::validate($approvedArgs, $toolInstance->getParametersSchema(), $operationName);
        } catch (Throwable $e) {
            $this->recordResumeValidationFailure($task, $taskId, $pendingToolCall, $e);
            return;
        }

        $result = $this->orchestrator->safeExecute($toolInstance, $approvedArgs, $state->agentId, $taskId);
        $this->recordResumeExecutionResult($task, $taskId, $pendingToolCall, $approvedArgs, $result);
    }

    /**
     * Worker-mode: persist the approval only. `executed_at IS NULL` is the
     * "approved, awaiting execution" sentinel that {@see TickPhaseRunner::runTick()}
     * picks up — don't set executed_at here.
     *
     * @param array<string, mixed> $approvedArgs
     */
    private function recordApprovalOnly(int $taskId, string $providerCallId, array $approvedArgs): void
    {
        ToolCallModel::where('task_id', $taskId)
            ->where('provider_call_id', $providerCallId)
            ->update([
                'status'             => 'APPROVED',
                'approved_arguments' => json_encode($approvedArgs, JSON_THROW_ON_ERROR),
            ]);
    }

    private function reopenForRemainingPending(Task $task, AgentState $state, array $remaining): void
    {
        $remainingState = new AgentState(
            taskId: $state->taskId,
            agentId: $state->agentId,
            pendingToolCalls: $remaining,
            messageSnapshot: $state->messageSnapshot,
            stepCount: $state->stepCount,
            maxSteps: $state->maxSteps,
            pausedAt: date('Y-m-d\TH:i:s\Z'),
        );

        Task::where('id', $task->id)->update([
            'status'        => 'PENDING_APPROVAL',
            'pending_state' => $remainingState->toJson(),
        ]);

        $this->logger?->info('Partial approval — task re-paused for remaining tools', [
            'task_id'         => $task->id,
            'remaining_count' => count($remaining),
            'remaining_tools' => implode(', ', array_unique(array_map(
                static fn(DriverToolCall $tc): string => $tc->toolName,
                $remaining,
            ))),
            'worker_mode'     => $this->workerMode === WorkerMode::Sync ? 'sync' : 'worker',
        ]);
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
            content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
            context: new HistoryMessageContext(
                toolCallId: $pendingToolCall->providerCallId,
                toolName: $pendingToolCall->toolName,
            ),
        );

        ToolCallModel::where('task_id', $taskId)
            ->where('provider_call_id', $pendingToolCall->providerCallId)
            ->update([
                'status'         => 'APPROVED',
                'result_content' => ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
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
        // Query-builder update() intentionally bypasses Eloquent casts
        // — writing the JSON string here lands the right shape on disk
        // and the `array` cast decodes it back to an array on read.
        // Do NOT 'simplify' this to ToolCall::create()/update(): those
        // paths re-encode through the cast and would double-encode the
        // value (the same anti-pattern PR #150 fixed in
        // Orchestrator::appendHistory and ToolCallExecutor).
        ToolCallModel::where('task_id', $taskId)
            ->where('provider_call_id', $pendingToolCall->providerCallId)
            ->update([
                'status'             => 'APPROVED',
                'approved_arguments' => json_encode($approvedArgs, JSON_THROW_ON_ERROR),
                'result_content'     => ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
                'result_data'        => $result->data ? json_encode($result->data, JSON_THROW_ON_ERROR) : null,
                'executed_at'        => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
            context: new HistoryMessageContext(
                toolCallId: $pendingToolCall->providerCallId,
                toolName: $pendingToolCall->toolName,
            ),
        );
    }

    /**
     * Marks DB rows stuck in PENDING_APPROVAL with no matching entry in the
     * saved `pending_state` as REJECTED. State/DB-drift only — partial-approval
     * branches off before this point so legitimate "still awaiting decision"
     * rows are not affected.
     */
    private function cleanupStrandedApprovals(Task $task, int $taskId): void
    {
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
        // If SubAgentService.spawn() flipped the parent to AWAITING_SUB_AGENTS
        // mid-batch (any approved sub_agent call), leave that status alone —
        // the resume happens when every child terminates, not now.
        $current = Task::where('id', $taskId)->value('status');
        if ($current === 'AWAITING_SUB_AGENTS') {
            return;
        }

        $taskStatus = $this->workerMode === WorkerMode::Sync ? 'RUNNING' : 'QUEUED';
        Task::where('id', $taskId)->update(['status' => $taskStatus]);
    }

    private function markResumeFailed(int $taskId, Throwable $e): void
    {
        Task::where('id', $taskId)->update([
            'status'         => 'FAILED',
            'error_code'     => 'RESUME_FAILED',
            'error_message'  => Utf8Sanitizer::scrubString('Task resume failed: ' . $e->getMessage()),
            'failure_reason' => Utf8Sanitizer::scrubString($e->getMessage()),
        ]);
    }
}
