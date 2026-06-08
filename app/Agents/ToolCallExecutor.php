<?php

declare(strict_types=1);

namespace Spora\Agents;

use RuntimeException;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Per-call worker extracted from {@see Orchestrator::handleToolCalls()} to keep that
 * method under the SonarQube S3776 cognitive-complexity threshold.
 *
 * Handles the resolve → validate → execute-or-queue sequence for a single
 * {@see DriverToolCall} and reports the outcome as a {@see ToolCallDisposition}.
 *
 * Package-private collaborator: constructed and called only by {@see Orchestrator}.
 */
final class ToolCallExecutor
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
    ) {}

    public function executeOrQueue(
        DriverToolCall $toolCall,
        Agent           $agent,
        Task            $task,
        array           $enabledClasses,
    ): ToolCallDisposition {
        $toolInstance = $this->orchestrator->resolveToolByName($toolCall->toolName);
        $toolClass    = get_class($toolInstance);

        if (!in_array($toolClass, $enabledClasses, true)) {
            throw new RuntimeException("The LLM attempted to call tool '{$toolCall->toolName}' which is not enabled for this agent.");
        }

        $operationName        = 'default';
        $operationDescription = null;
        $usesOperations       = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

        if ($usesOperations) {
            $operationName        = $this->orchestrator->callTraitMethod($toolInstance, 'getOperationName', [$toolCall->arguments]);
            $operationDescription = $this->orchestrator->callTraitMethod($toolInstance, 'getOperationDescription', [$operationName]);

            if (!$this->orchestrator->isOperationEnabled($toolInstance, $operationName, $agent->id)) {
                $this->persistDisabledOperation($task, $agent, $toolCall, $toolClass, $operationName, $operationDescription);
                return ToolCallDisposition::OperationDisabled;
            }
        }

        $requiresApproval = $this->orchestrator->resolveRequiresApproval($toolInstance, $toolClass, $agent->id, $toolCall->arguments);

        $toolCallRecord = $this->createPendingRecord(
            $task,
            $agent,
            $toolCall,
            $toolClass,
            $operationName,
            $operationDescription,
            $requiresApproval,
            $toolInstance,
        );

        try {
            SchemaValidator::validate($toolCall->arguments, $toolInstance->getParametersSchema());
        } catch (Throwable $e) {
            $this->recordValidationFailure($task, $toolCallRecord, $e, $toolCall);
            return ToolCallDisposition::ValidationFailed;
        }

        if (!$requiresApproval) {
            $this->executeAndRecordResult($task, $toolCallRecord, $toolInstance, $toolCall, $agent);
            return ToolCallDisposition::Executed;
        }

        return ToolCallDisposition::AwaitingApproval;
    }

    private function createPendingRecord(
        Task            $task,
        Agent           $agent,
        DriverToolCall  $toolCall,
        string          $toolClass,
        string          $operationName,
        ?string         $operationDescription,
        bool            $requiresApproval,
        ToolInterface   $toolInstance,
    ): ToolCallModel {
        return ToolCallModel::create([
            'task_id'               => $task->id,
            'agent_id'              => $agent->id,
            'provider_call_id'      => $toolCall->providerCallId,
            'tool_name'             => $toolCall->toolName,
            'tool_class'            => $toolClass,
            'tool_type'             => $requiresApproval ? 'output' : 'input',
            'operation'             => $operationName,
            'operation_description' => $operationDescription,
            'status'                => 'PENDING_APPROVAL',
            'proposed_arguments'    => json_encode($toolCall->arguments, JSON_THROW_ON_ERROR),
            'human_description'     => $toolInstance->describeAction($toolCall->arguments),
        ]);
    }

    private function persistDisabledOperation(
        Task            $task,
        Agent           $agent,
        DriverToolCall  $toolCall,
        string          $toolClass,
        string          $operationName,
        ?string         $operationDescription,
    ): void {
        ToolCallModel::create([
            'task_id'               => $task->id,
            'agent_id'              => $agent->id,
            'provider_call_id'      => $toolCall->providerCallId,
            'tool_name'             => $toolCall->toolName,
            'tool_class'            => $toolClass,
            'tool_type'             => 'operation',
            'operation'             => $operationName,
            'operation_description' => $operationDescription,
            'status'                => 'DISABLED',
            'proposed_arguments'    => json_encode($toolCall->arguments, JSON_THROW_ON_ERROR),
            'human_description'     => $operationDescription,
        ]);

        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: "Operation '{$operationName}' is disabled for this agent.",
            context: new HistoryMessageContext(
                toolCallId: $toolCall->providerCallId,
                toolName: $toolCall->toolName,
            ),
        );
    }

    private function recordValidationFailure(
        Task          $task,
        ToolCallModel $toolCallRecord,
        Throwable     $e,
        DriverToolCall $toolCall,
    ): void {
        $result = new ToolResult(false, 'Validation Error: ' . $e->getMessage());

        \Illuminate\Database\Capsule\Manager::connection()->transaction(function () use ($toolCallRecord, $result, $task, $toolCall): void {
            $toolCallRecord->update([
                'status'         => 'APPROVED',
                'result_content' => $result->content,
                'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);
            $this->orchestrator->appendHistory(
                taskId: $task->id,
                role: 'tool',
                content: $result->content,
                context: new HistoryMessageContext(
                    toolCallId: $toolCall->providerCallId,
                    toolName: $toolCall->toolName,
                ),
            );
        });
    }

    private function executeAndRecordResult(
        Task           $task,
        ToolCallModel  $toolCallRecord,
        ToolInterface  $toolInstance,
        DriverToolCall $toolCall,
        Agent          $agent,
    ): void {
        $result = $this->orchestrator->safeExecute(
            $toolInstance,
            $toolCall->arguments,
            $agent->id,
            $task->id,
            $task->user_id,
        );

        \Illuminate\Database\Capsule\Manager::connection()->transaction(function () use ($toolCallRecord, $result, $task, $toolCall): void {
            $toolCallRecord->update([
                'status'         => 'APPROVED',
                'result_content' => $result->content,
                'result_data'    => $result->data ? json_encode($result->data, JSON_THROW_ON_ERROR) : null,
                'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);
            $this->orchestrator->appendHistory(
                taskId: $task->id,
                role: 'tool',
                content: $result->content,
                context: new HistoryMessageContext(
                    toolCallId: $toolCall->providerCallId,
                    toolName: $toolCall->toolName,
                ),
            );
        });
    }
}
