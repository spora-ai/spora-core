<?php

declare(strict_types=1);

namespace Spora\Agents;

/**
 * Outcome of processing a single tool call inside {@see ToolCallExecutor::executeOrQueue()}.
 *
 * The outer {@see ToolCallBatchHandler::dispatchOneToolCall()} loop uses this
 * to decide whether the call should be added to the pending-approval batch.
 */
enum ToolCallDisposition: string
{
    /** The LLM invoked a tool the agent has not enabled — the gate throws and the caller catches it. */
    case SystemError = 'system_error';

    /** The operation is disabled for this agent; record + history written, no execution. */
    case OperationDisabled = 'operation_disabled';

    /** Schema validation failed; record + history written, no execution. */
    case ValidationFailed = 'validation_failed';

    /** Tool executed inline (auto-approve); record + history written, no approval needed. */
    case Executed = 'executed';

    /** Tool queued for human approval; record written, awaiting user input. */
    case AwaitingApproval = 'awaiting_approval';

    /** Tool queued for user answers (ask_user_question); record written, task parked in AWAITING_INPUT. */
    case AwaitingInput = 'awaiting_input';

    /** The provider reused a call id already proposed in this task; history written, no record created. */
    case DuplicateCallId = 'duplicate_call_id';
}
