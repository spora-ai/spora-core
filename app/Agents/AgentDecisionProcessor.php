<?php

declare(strict_types=1);

namespace Spora\Agents;

use InvalidArgumentException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\ScrubDataUrls;

/**
 * Splits per-call approval / rejection decisions into approved and rejected
 * batches and records the rejected rows in the same DB transaction as the
 * orchestrator's `resume()`.
 *
 * Approved rows are passed back to the caller (the orchestrator hands them
 * to `ApprovedBatchExecutor`). Rejected rows are stamped here — status,
 * `rejected_at`, `rejected_by`, `reject_reason`, plus a `role:'tool'`
 * history row carrying `toolCallId` + `toolName` so the LLM sees the
 * rejection in its next round-trip.
 *
 * The orchestrator reference is held only to call its `appendHistory`
 * helper; no business state lives on the orchestrator.
 */
final class AgentDecisionProcessor
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
    ) {}

    /**
     * @param  list<array<string, mixed>> $decisions
     * @return array{
     *     list<array{provider_call_id: string, arguments: array<string, mixed>}>,
     *     list<array{provider_call_id: string, reason: string}>
     * }
     */
    public function splitDecisions(array $decisions, AgentState $state): array
    {
        if ($decisions === []) {
            throw new InvalidArgumentException('decisions must be a non-empty array.');
        }

        $pendingIds = $this->indexPendingProviderCallIds($state);
        $approvedBatch = [];
        $rejectedBatch = [];

        /** @var list<mixed> $decisions */
        foreach ($decisions as $index => $decision) {
            $entry = $this->classifyDecision($decision, $index, $pendingIds);
            if ($entry['decision'] === 'approve') {
                $approvedBatch[] = $entry;
            } else {
                $rejectedBatch[] = $entry;
            }
        }

        return [$approvedBatch, $rejectedBatch];
    }

    /**
     * @param list<array{provider_call_id: string, reason: string}> $rejectedBatch
     */
    public function markRejectionBatch(Task $task, array $rejectedBatch): void
    {
        foreach ($rejectedBatch as $rejected) {
            $this->markSingleRejection($task, $rejected);
        }
    }

    /**
     * @return array<string, true>
     */
    private function indexPendingProviderCallIds(AgentState $state): array
    {
        $pendingIds = [];
        foreach ($state->pendingToolCalls as $pendingToolCall) {
            $pendingIds[$pendingToolCall->providerCallId] = true;
        }
        return $pendingIds;
    }

    /**
     * @param mixed $decision
     * @param array<string, true> $pendingIds
     * @return array{provider_call_id: string, decision: 'approve', arguments: array<string, mixed>}
     *        |array{provider_call_id: string, decision: 'reject', reason: string}
     */
    private function classifyDecision($decision, int $index, array $pendingIds): array
    {
        if (!is_array($decision)) {
            throw new InvalidArgumentException("Decision at index {$index} must be an array.");
        }
        $providerCallId = $this->validateProviderCallId($decision, $index, $pendingIds);
        $choice = $this->validateDecisionChoice($decision, $index);
        return $choice === 'approve'
            ? $this->buildApprovedEntry($decision, $providerCallId, $index)
            : $this->buildRejectedEntry($decision, $providerCallId, $index);
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, true> $pendingIds
     */
    private function validateProviderCallId(array $decision, int $index, array $pendingIds): string
    {
        $raw = $decision['provider_call_id'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException('provider_call_id is required in every decision.');
        }
        $providerCallId = trim($raw);
        if (!isset($pendingIds[$providerCallId])) {
            throw new InvalidArgumentException("Decision at index {$index} has provider_call_id '{$providerCallId}' which is not pending approval.");
        }
        return $providerCallId;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function validateDecisionChoice(array $decision, int $index): string
    {
        $choice = $decision['decision'] ?? null;
        if (!is_string($choice) || !in_array($choice, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException("Decision at index {$index} must have decision either 'approve' or 'reject'.");
        }
        return $choice;
    }

    /**
     * @param array<string, mixed> $decision
     * @return array{provider_call_id: string, decision: 'approve', arguments: array<string, mixed>}
     */
    private function buildApprovedEntry(array $decision, string $providerCallId, int $index): array
    {
        $arguments = $decision['arguments'] ?? null;
        if (!is_array($arguments)) {
            throw new InvalidArgumentException("Decision at index {$index} has decision 'approve' but arguments is not an array.");
        }
        return [
            'provider_call_id' => $providerCallId,
            'decision'         => 'approve',
            'arguments'        => $arguments,
        ];
    }

    /**
     * @param array<string, mixed> $decision
     * @return array{provider_call_id: string, decision: 'reject', reason: string}
     */
    private function buildRejectedEntry(array $decision, string $providerCallId, int $index): array
    {
        $reason = $decision['reason'] ?? 'User rejected';
        if (!is_string($reason)) {
            throw new InvalidArgumentException("Decision at index {$index} has decision 'reject' but reason is not a string.");
        }
        $reason = trim($reason);
        return [
            'provider_call_id' => $providerCallId,
            'decision'         => 'reject',
            'reason'           => $reason === '' ? 'User rejected' : $reason,
        ];
    }

    /**
     * @param array{provider_call_id: string, reason: string} $rejected
     */
    private function markSingleRejection(Task $task, array $rejected): void
    {
        $model = ToolCallModel::where('task_id', $task->id)
            ->where('provider_call_id', $rejected['provider_call_id'])
            ->where('status', 'PENDING_APPROVAL')
            ->first();
        if ($model === null) {
            throw new InvalidArgumentException("provider_call_id '{$rejected['provider_call_id']}' is not pending approval.");
        }

        $model->update([
            'status'        => 'REJECTED',
            'rejected_at'   => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            'rejected_by'   => $task->triggerUserId(),
            'reject_reason' => $rejected['reason'],
        ]);

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub("Action rejected by user: {$rejected['reason']}"),
            context: new HistoryMessageContext(
                toolCallId: $model->provider_call_id,
                toolName: $model->tool_name,
            ),
        );
    }
}
