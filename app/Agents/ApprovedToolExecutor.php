<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\ScrubDataUrls;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Tools\AskUserQuestionTool;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Throwable;

/**
 * Runs the tool calls an operator approved, when the daemon picks them up.
 *
 * Between the approval and the pickup the agent's permissions may have
 * changed, so the call is authorized again here — and the operation the
 * **final** arguments select is the one that is authorized. An approval
 * that replaces the arguments is a new proposal, not a new permission.
 */
final class ApprovedToolExecutor
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
    ) {}

    /**
     * @param array<string, mixed> $approvedArgs
     */
    public function execute(
        Task $task,
        ToolCallModel $row,
        string $providerCallId,
        string $toolName,
        array $approvedArgs,
        ?string $operationName,
    ): void {
        $toolInstance = $this->orchestrator->resolveToolByName($toolName);

        // Re-check authorization at execution time: between the user approving
        // the batch and the daemon picking it up here, an admin may have revoked
        // this tool from the agent or disabled the specific op. The
        // proposal-time gate in {@see ToolCallExecutor::executeOrQueue()} only
        // runs at proposal time and cannot defend against drift.
        $rejectReason = $this->revokedToolReason($task, $toolInstance, $toolName)
            ?? $this->operationRejectionReason($task, $toolInstance, $approvedArgs, $operationName, $toolName);

        if ($rejectReason !== null) {
            $this->recordRevokedToolCall($task, $row, $toolName, $rejectReason);
            return;
        }

        try {
            SchemaValidator::validate($approvedArgs, $toolInstance->getParametersSchema(), $operationName);
        } catch (Throwable $e) {
            $this->recordApprovedFailure($task, $row, $toolName, 'Validation Error: ' . $e->getMessage());
            return;
        }

        $this->runApprovedTool($task, $row, $providerCallId, $toolName, $approvedArgs, $toolInstance);
    }

    /**
     * Why the tool must not run at all, or null when it may.
     */
    private function revokedToolReason(Task $task, ToolInterface $toolInstance, string $toolName): ?string
    {
        $enabledClasses = AgentTool::where('agent_id', $task->agent_id)
            ->pluck('tool_class')->all();

        if (!in_array(get_class($toolInstance), $enabledClasses, true)) {
            return "tool '{$toolName}' was revoked from this agent before approval was processed";
        }

        return null;
    }

    /**
     * Why the operation must not run, or null when it may.
     *
     * @param array<string, mixed> $approvedArgs
     */
    private function operationRejectionReason(
        Task $task,
        ToolInterface $toolInstance,
        array $approvedArgs,
        ?string $operationName,
        string $toolName,
    ): ?string {
        $toolClass = get_class($toolInstance);

        if ($operationName === null
            || !in_array(HasOperations::class, class_uses_recursive($toolClass), true)
        ) {
            return null;
        }

        // The approval may replace the arguments, and the replacement
        // selects the operation on its own: `action=delete_folder`
        // dispatches the folder deletion, whatever the proposal said.
        // Authorize the operation the final arguments actually select,
        // and refuse a call whose operation changed.
        $approvedOperation = $this->orchestrator->resolveOperationName($toolInstance, $approvedArgs, $task->agent_id);

        if ($approvedOperation !== $operationName) {
            return "the approval changed operation '{$operationName}' to '{$approvedOperation}'";
        }

        return $this->disabledOperationReason($toolInstance, $operationName, $toolName, $task->agent_id);
    }

    private function disabledOperationReason(
        ToolInterface $toolInstance,
        string $operationName,
        string $toolName,
        int $agentId,
    ): ?string {
        if ($this->orchestrator->isOperationEnabled($toolInstance, $operationName, $agentId)) {
            return null;
        }

        return "operation '{$operationName}' of tool '{$toolName}' was disabled before approval was processed";
    }

    /**
     * @param array<string, mixed> $approvedArgs
     */
    private function runApprovedTool(
        Task $task,
        ToolCallModel $row,
        string $providerCallId,
        string $toolName,
        array $approvedArgs,
        ToolInterface $toolInstance,
    ): void {
        // safeExecute() turns the tool's own failures into a failed
        // ToolResult, so there is no outer try/catch here. The usage-limit
        // gate is the exception: it throws out of safeExecute() and reaches
        // TickPhaseRunner::executeApprovedPendingToolsForTask().
        $result = $this->orchestrator->safeExecute($toolInstance, $approvedArgs, $task->agent_id, $task->id);

        // Query-builder update() bypasses Eloquent's `array` cast on
        // `approved_arguments`, which would double-encode the JSON string.
        //
        // The write names the row, not the call id: a later proposal may reuse
        // the id, and the completed call keeps the outcome it had.
        ToolCallModel::where('id', $row->id)->update([
            'result_content' => ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
            'result_data'    => $result->data ? json_encode($result->data, JSON_THROW_ON_ERROR) : null,
            'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
        ]);

        if ($toolInstance instanceof AskUserQuestionTool) {
            // An approved question still has to park the task on its
            // question batch — the operator answers it, not the approval.
            $this->orchestrator->toolCallExecutor->parkApprovedQuestion(
                $task,
                $row,
                $result,
                $approvedArgs,
            );
            return;
        }

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
            context: new HistoryMessageContext(
                toolCallId: $providerCallId,
                toolName: $toolName,
            ),
        );
    }

    /**
     * Stamp a `tool_calls` row REJECTED for a tool that the agent has been
     * revoked from or had a specific op disabled, and append a `'tool'`
     * history message carrying the cause.
     *
     * `rejected_by` is left null: revocation is an admin/system action with
     * no single user-actor in this column's model. The reason lives in
     * `reject_reason` and in the appended history row, which is what the
     * LLM sees on its next round-trip.
     *
     * The write names the row, not the call id: a later proposal may reuse
     * the id, and a completed call keeps the outcome it had.
     */
    private function recordRevokedToolCall(
        Task $task,
        ToolCallModel $row,
        string $toolName,
        string $rejectReason,
    ): void {
        ToolCallModel::where('id', $row->id)->update([
            'status'        => 'REJECTED',
            'rejected_at'   => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            'rejected_by'   => null,
            'reject_reason' => $rejectReason,
        ]);

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString("Action rejected: {$rejectReason}")),
            context: new HistoryMessageContext(
                toolCallId: (string) $row->provider_call_id,
                toolName: $toolName,
            ),
        );
    }

    private function recordApprovedFailure(
        Task $task,
        ToolCallModel $row,
        string $toolName,
        string $message,
    ): void {
        $scrubbed = ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($message));

        ToolCallModel::where('id', $row->id)->update([
            'result_content' => $scrubbed,
            'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
        ]);

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: $scrubbed,
            context: new HistoryMessageContext(
                toolCallId: (string) $row->provider_call_id,
                toolName: $toolName,
            ),
        );
    }
}
