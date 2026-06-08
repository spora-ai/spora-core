<?php

declare(strict_types=1);

namespace Spora\Agents;

/**
 * Outcome of processing a single tool call inside {@see ToolCallExecutor::executeOrQueue()}.
 *
 * The outer {@see Orchestrator::handleToolCalls()} loop uses this to decide whether the
 * call should be added to the pending-approval batch.
 */
enum ToolCallDisposition: string
{
    /** The LLM invoked a tool the agent has not enabled — caught at the loop level. */
    case SystemError = 'system_error';

    /** The operation is disabled for this agent; record + history written, no execution. */
    case OperationDisabled = 'operation_disabled';

    /** Schema validation failed; record + history written, no execution. */
    case ValidationFailed = 'validation_failed';

    /** Tool executed inline (auto-approve); record + history written, no approval needed. */
    case Executed = 'executed';

    /** Tool queued for human approval; record written, awaiting user input. */
    case AwaitingApproval = 'awaiting_approval';
}
