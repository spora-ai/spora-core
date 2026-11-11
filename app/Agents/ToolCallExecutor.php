<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\Exceptions\ToolNotEnabledException;
use Spora\Agents\ValueObjects\AgentState;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Task;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\ScrubDataUrls;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Tools\AskUserQuestionTool;
use Spora\Tools\PendingQuestion;
use Spora\Tools\PendingQuestionBatch;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Spora\Tools\WrappedList;
use Throwable;

/**
 * Per-call worker for {@see ToolCallBatchHandler::dispatchOneToolCall()}:
 * resolves, validates, and either executes or queues a single
 * {@see DriverToolCall}, reporting the outcome as a {@see ToolCallDisposition}.
 *
 * Package-private collaborator: {@see Orchestrator} constructs it, and the
 * other extracted services reach it through the orchestrator.
 */
final class ToolCallExecutor
{
    private readonly ToolOperationResolver $toolOperations;

    public function __construct(
        private readonly Orchestrator $orchestrator,
        ?ToolOperationResolver $toolOperations = null,
    ) {
        $this->toolOperations = $toolOperations ?? new ToolOperationResolver();
    }

    public function executeOrQueue(
        DriverToolCall $toolCall,
        Agent           $agent,
        Task            $task,
    ): ToolCallDisposition {
        $toolInstance = $this->orchestrator->resolveToolByName($toolCall->toolName);
        $toolClass    = get_class($toolInstance);

        $gated = $this->gateDisposition($agent, $task, $toolCall, $toolClass);

        return $gated ?? $this->executeGatedCall($toolCall, $agent, $task, $toolInstance, $toolClass);
    }

    /**
     * The disposition the call must take before anything is recorded, or
     * null when the call may proceed.
     */
    private function gateDisposition(
        Agent           $agent,
        Task            $task,
        DriverToolCall $toolCall,
        string         $toolClass,
    ): ?ToolCallDisposition {
        // The operator's decision names a proposal by its provider call id,
        // so the id has to name exactly one proposal. Two proposals that
        // share an id are ambiguous only while both are live — a finished
        // call leaves nothing to authorize — so a reuse is refused while
        // another call under that id is still awaiting a decision or still
        // waiting to run.
        $liveCallExists = ToolCallModel::where('task_id', $task->id)
            ->where('provider_call_id', $toolCall->providerCallId)
            ->where(function ($query): void {
                $query->where('status', 'PENDING_APPROVAL')
                    ->orWhere(function ($approved): void {
                        $approved->where('status', 'APPROVED')->whereNull('executed_at');
                    });
            })
            ->exists();

        if ($liveCallExists) {
            $this->recordDuplicateCallId($task, $toolCall);
            return ToolCallDisposition::DuplicateCallId;
        }

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

        return null;
    }

    private function executeGatedCall(
        DriverToolCall $toolCall,
        Agent           $agent,
        Task            $task,
        ToolInterface   $toolInstance,
        string          $toolClass,
    ): ToolCallDisposition {
        $operationName        = 'default';
        $operationDescription = null;
        $hasOperations        = in_array(HasOperations::class, class_uses_recursive($toolClass), true);

        if ($hasOperations) {
            // The op is resolved the same way the orchestrator resolves it
            // for the approval default, so the op that is validated, the op
            // that is enablement-checked and the op whose approval default
            // is read are always the same one.
            $operationName = $this->toolOperations->resolveOperationName($toolInstance, $toolCall->arguments, $agent->id);

            // `getOperationDescription()` lives on the HasOperations trait,
            // not on ToolInterface, so it goes through a callable.
            /** @var callable(string): string $describeOperation */
            $describeOperation = [$toolInstance, 'getOperationDescription'];
            $operationDescription = $describeOperation($operationName);

            if (!$this->orchestrator->isOperationEnabled($toolInstance, $operationName, $agent->id)) {
                $this->persistDisabledOperation($task, $agent, $toolCall, $toolClass, $operationName, $operationDescription);
                return ToolCallDisposition::OperationDisabled;
            }
        }

        $requiresApproval = $this->orchestrator->resolveRequiresApproval($toolInstance, $toolClass, $agent->id, $toolCall->arguments);

        // ask_user_question is structurally a "pause-the-tick" tool, not a
        // normal execute-and-return. It validates its arguments, returns a
        // placeholder ToolResult, and the caller is expected to treat the
        // call as AwaitingInput + park the task on a PendingQuestionBatch.
        // Routing it through the inline-execute path would flip the task
        // to APPROVED/COMPLETED before any answer ever lands.
        if ($toolInstance instanceof AskUserQuestionTool) {
            return $this->dispatchQuestion(
                $toolCall,
                $agent,
                $task,
                $toolInstance,
                $operationName,
                $operationDescription,
                $requiresApproval,
            );
        }

        $toolCallRecord = $this->createPendingRecord($task, $agent, $toolCall, $operationName, $operationDescription, $requiresApproval, $toolInstance);

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
     * The question tool's own path: it parks the task for answers rather
     * than returning a result.
     */
    private function dispatchQuestion(
        DriverToolCall $toolCall,
        Agent           $agent,
        Task            $task,
        ToolInterface   $toolInstance,
        string          $operationName,
        ?string         $operationDescription,
        bool            $requiresApproval,
    ): ToolCallDisposition {
        if ($requiresApproval) {
            // The operator configured this operation to need approval,
            // so the question waits for a decision like any other call.
            // The approved run parks the task on the question batch.
            $this->createPendingRecord($task, $agent, $toolCall, $operationName, $operationDescription, true, $toolInstance);
            return ToolCallDisposition::AwaitingApproval;
        }

        $toolCallRecord = $this->createPendingRecord($task, $agent, $toolCall, $operationName, $operationDescription, false, $toolInstance);

        return $this->validateAndParkQuestion($task, $toolCall, $toolInstance, $agent, $toolCallRecord, $operationName);
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
     * AskUserQuestion-specific path: validate the schema, run the tool's
     * deeper validation (header length, option counts, …) by calling it,
     * and on success park the task on a fresh PendingQuestionBatch and
     * flip the status to AWAITING_INPUT. On validation failure, record
     * the rejection so the LLM sees a tool row carrying the error.
     *
     * Mirrors {@see validateAndExecute()} but writes the row with
     * status='APPROVED' + a placeholder result so the tool-call row in
     * the timeline still reflects "the LLM called this tool and it ran",
     * while the *task* status reflects the parked input state.
     */
    private function validateAndParkQuestion(
        Task           $task,
        DriverToolCall $toolCall,
        ToolInterface  $toolInstance,
        Agent          $agent,
        ToolCallModel  $toolCallRecord,
        ?string        $operationName,
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

        $result = $this->orchestrator->safeExecute($toolInstance, $toolCall->arguments, $agent->id, $task->id);

        $scrubbed = ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content));

        Capsule::connection()->transaction(function () use ($task, $toolCall, $toolCallRecord, $scrubbed, $result): void {
            // The row is written as APPROVED with executed_at stamped so the
            // approval-pending UI doesn't surface the call; the parked-task
            // status on the task row is the source of truth for "waiting".
            $toolCallRecord->update([
                'status'         => 'APPROVED',
                'result_content' => $scrubbed,
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

            if (!$result->success) {
                // Tool's deeper validation failed (e.g. >4 questions, header
                // too long). The ToolResult carries the human-readable error;
                // we leave the task running so the LLM can retry on its next
                // turn. We also need to drop the just-stamped row out of the
                // batch picker's view, so we re-read pending_state and write
                // back a state without the failed batch.
                return;
            }

            $this->parkQuestionBatch($task, $toolCall->providerCallId, $toolCall->arguments);
        });

        return $result->success
            ? ToolCallDisposition::AwaitingInput
            : ToolCallDisposition::Executed;
    }

    /**
     * Park the task on a question batch after an approved question call ran.
     *
     * The approval path has to reach the same waiting-for-input lifecycle
     * the inline path parks on: the questions are persisted and the task
     * flips to AWAITING_INPUT, so the operator's answers unblock it.
     *
     * @param array<string, mixed> $arguments
     */
    public function parkApprovedQuestion(
        Task          $task,
        ToolCallModel $toolCallRecord,
        ToolResult    $result,
        array         $arguments,
    ): void {
        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
            context: new HistoryMessageContext(
                toolCallId: (string) $toolCallRecord->provider_call_id,
                toolName: (string) $toolCallRecord->tool_name,
            ),
        );

        if (!$result->success) {
            return;
        }

        $this->parkQuestionBatch($task, (string) $toolCallRecord->provider_call_id, $arguments);
    }

    /**
     * Persist the questions and flip the task to AWAITING_INPUT.
     *
     * @param array<string, mixed> $arguments
     */
    private function parkQuestionBatch(Task $task, string $providerCallId, array $arguments): void
    {
        $questions = [];
        // The provider's `{"item": …}` envelope: the tool unwrapped it to
        // validate, and the parked question has to be the validated one.
        foreach (WrappedList::unwrapToList($arguments['questions'] ?? []) as $rawQuestion) {
            if (!is_array($rawQuestion)) {
                continue;
            }
            $questions[] = PendingQuestion::fromLlmInput($rawQuestion);
        }
        $batch = PendingQuestionBatch::build($providerCallId, $questions);

        // Merge into any existing pending_state (a later tool call in
        // the same tick that lands as AwaitingApproval keeps its row in
        // pending_tool_calls alongside the new batch).
        /** @var list<PendingQuestionBatch> $existingQuestions */
        $existingQuestions = [];
        /** @var list<\Spora\Drivers\ValueObjects\ToolCall> $existingToolCalls */
        $existingToolCalls = [];
        $existingSnapshot = [];
        if (is_string($task->pending_state) && $task->pending_state !== '') {
            try {
                $existing = AgentState::fromJson($task->pending_state);
                $existingQuestions = $existing->pendingQuestions;
                $existingToolCalls = $existing->pendingToolCalls;
                $existingSnapshot = $existing->messageSnapshot;
            } catch (Throwable) {
                // Bad JSON or shape drift — fall through with empty defaults.
            }
        }
        $mergedQuestions = array_merge($existingQuestions, [$batch]);
        $mergedState = new AgentState(
            taskId: $task->id,
            agentId: $task->agent_id,
            pendingToolCalls: $existingToolCalls,
            messageSnapshot: $existingSnapshot,
            stepCount: $task->step_count,
            maxSteps: $task->max_steps,
            pausedAt: gmdate(Orchestrator::ISO8601_UTC_FORMAT),
            pendingQuestions: $mergedQuestions,
        );

        Capsule::table('tasks')
            ->where('id', $task->id)
            ->update([
                'status'        => 'AWAITING_INPUT',
                'pending_state' => $mergedState->toJson(),
            ]);
    }

    /**
     * Tell the LLM that its second proposal reused a live call id.
     */
    private function recordDuplicateCallId(Task $task, DriverToolCall $toolCall): void
    {
        $this->orchestrator->appendHistory(
            taskId: $task->id,
            role: 'tool',
            content: ScrubDataUrls::scrub(Utf8Sanitizer::scrubString(
                "Tool call id '{$toolCall->providerCallId}' is already used by another call awaiting a decision in this task; this call was ignored.",
            )),
            context: new HistoryMessageContext(
                toolCallId: $toolCall->providerCallId,
                toolName: $toolCall->toolName,
            ),
        );
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
            // on save. Pre-encoding here double-encodes.
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
            // on save. Pre-encoding here double-encodes.
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

        Capsule::connection()->transaction(function () use ($toolCallRecord, $result, $task, $toolCall): void {
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

        Capsule::connection()->transaction(function () use ($toolCallRecord, $result, $task, $toolCall): void {
            $scrubbed = ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content));
            $toolCallRecord->update([
                'status'         => 'APPROVED',
                'result_content' => $scrubbed,
                // ToolCall::$casts['result_data'] => 'array' encodes on
                // save. Pre-encoding double-encodes.
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
