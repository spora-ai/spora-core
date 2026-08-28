<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\Exceptions\ToolNotEnabledException;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\ScrubDataUrls;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Per-call worker for {@see Orchestrator::handleToolCalls()}: resolves,
 * validates, and either executes or queues a single {@see DriverToolCall},
 * reporting the outcome as a {@see ToolCallDisposition}.
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
    ): ToolCallDisposition {
        $toolInstance = $this->orchestrator->resolveToolByName($toolCall->toolName);
        $toolClass    = get_class($toolInstance);

        // Re-load the current allow-list at gate-check time. The snapshot
        // TickPhaseRunner::prepareTickContext() captured at tick start is
        // still used by Orchestrator::buildToolDefinitions() for the LLM's
        // offered tool set, but the gate itself must trust the DB so a
        // revocation that landed while the LLM was mid-round-trip is
        // honoured.
        $currentEnabledClasses = AgentTool::where('agent_id', $agent->id)
            ->pluck('tool_class')->all();

        if (!in_array($toolClass, $currentEnabledClasses, true)) {
            throw new ToolNotEnabledException(
                "The LLM attempted to call tool '{$toolCall->toolName}' which is not enabled for this agent.",
            );
        }

        $operationName        = 'default';
        $operationDescription = null;
        if (in_array(HasOperations::class, class_uses_recursive($toolClass), true)) {
            // The orchestrator previously wrapped the [$object, $method] callable
            // in a `callTraitMethod` helper, but the body was a literal one-liner
            // — the indirection just kept the variadic-spread call away from
            // ToolCallExecutor. Inlining keeps the call site adjacent to the
            // `HasOperations` trait it dispatches into.
            /** @var callable */
            $nameGetter = [$toolInstance, 'getOperationName'];
            $descGetter = [$toolInstance, 'getOperationDescription'];
            $operationName        = $nameGetter($toolCall->arguments);
            $operationDescription = $descGetter($operationName);

            if (!$this->orchestrator->isOperationEnabled($toolInstance, $operationName, $agent->id)) {
                $this->persistDisabledOperation($task, $agent, $toolCall, $toolClass, $operationName, $operationDescription);
                return ToolCallDisposition::OperationDisabled;
            }
        }

        $requiresApproval = $this->orchestrator->resolveRequiresApproval($toolInstance, $toolClass, $agent->id, $toolCall->arguments);
        $toolCallRecord   = $this->createPendingRecord($task, $agent, $toolCall, $operationName, $operationDescription, $requiresApproval, $toolInstance);

        $hasOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);
        return $this->validateAndExecute(
            $task,
            $toolCall,
            $toolInstance,
            $agent,
            $toolCallRecord,
            $requiresApproval,
            $hasOperations ? $operationName : null,
        );
    }

    /**
     * Validate the proposed arguments, then either execute immediately or
     * leave the record PENDING_APPROVAL for the resume() flow to pick up.
     *
     * `$operationName` is forwarded to {@see SchemaValidator::validate()} so
     * per-op `required[]` bindings declared via `#[ToolParameter]` are narrowed
     * against the actual op being dispatched. Pass `null` for tools without
     * operations (no narrowing is needed and the validator falls back to its
     * pre-narrowing behaviour).
     */
    private function validateAndExecute(
        Task           $task,
        DriverToolCall $toolCall,
        ToolInterface  $toolInstance,
        Agent          $agent,
        ToolCallModel  $toolCallRecord,
        bool           $requiresApproval,
        ?string        $operationName = null,
    ): ToolCallDisposition {
        try {
            SchemaValidator::validate(
                $toolCall->arguments,
                $toolInstance->getParametersSchema(),
                $operationName,
            );
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

    /**
     * Persist a PENDING_APPROVAL ToolCallModel row. The `tool_class` is
     * derived from the tool instance rather than passed in.
     */
    private function createPendingRecord(
        Task           $task,
        Agent          $agent,
        DriverToolCall $toolCall,
        string         $operationName,
        ?string        $operationDescription,
        bool           $requiresApproval,
        ToolInterface  $toolInstance,
    ): ToolCallModel {
        return ToolCallModel::create([
            'task_id'               => $task->id,
            'agent_id'              => $agent->id,
            'provider_call_id'      => $toolCall->providerCallId,
            'tool_name'             => $toolCall->toolName,
            'tool_class'            => get_class($toolInstance),
            'tool_type'             => $requiresApproval ? 'output' : 'input',
            'operation'             => $operationName,
            'operation_description' => $operationDescription,
            'status'                => 'PENDING_APPROVAL',
            // ToolCall::$casts['proposed_arguments'] => 'array' encodes
            // on save. Pre-encoding here double-encodes (the same
            // pattern PR #150 fixed in Orchestrator::appendHistory).
            'proposed_arguments'    => $toolCall->arguments,
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
            // ToolCall::$casts['proposed_arguments'] => 'array' encodes
            // on save. Pre-encoding here double-encodes (the same
            // pattern PR #150 fixed in Orchestrator::appendHistory).
            'proposed_arguments'    => $toolCall->arguments,
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
            $scrubbed = ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content));
            $toolCallRecord->update([
                'status'         => 'APPROVED',
                'result_content' => $scrubbed,
                'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);
            $this->orchestrator->appendHistory(
                taskId: $task->id,
                role: 'tool',
                content: $scrubbed,
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
        );

        \Illuminate\Database\Capsule\Manager::connection()->transaction(function () use ($toolCallRecord, $result, $task, $toolCall): void {
            $scrubbed = ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content));
            $toolCallRecord->update([
                'status'         => 'APPROVED',
                'result_content' => $scrubbed,
                // ToolCall::$casts['result_data'] => 'array' encodes on
                // save. Pre-encoding double-encodes (same pattern PR
                // #150 fixed in Orchestrator::appendHistory).
                'result_data'    => $result->data,
                'executed_at'    => date(Orchestrator::DB_TIMESTAMP_FORMAT),
            ]);
            $this->orchestrator->appendHistory(
                taskId: $task->id,
                role: 'tool',
                content: $scrubbed,
                context: new HistoryMessageContext(
                    toolCallId: $toolCall->providerCallId,
                    toolName: $toolCall->toolName,
                ),
            );
        });
    }
}
