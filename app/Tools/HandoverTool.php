<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\HandoverServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets the LLM transfer the running chat to a different agent that the
 * user has pre-approved via the `allowed_target_agents` multi-select.
 *
 * The source task is closed; a new Task is started on the target agent
 * with the LLM-supplied `context_summary` as the first user message and
 * `parent_task_id` linking back to the source for UI breadcrumb rendering.
 *
 * Example front-end usage (for the ToolSettingField "multi-select"):
 *   GET /api/v1/agents?select=id,name
 *
 * Example LLM-facing schema (for the tool definition):
 *   handover tool
 *     Allowed target agents: ["Legal Agent (#1)", "Sales Agent (#5)"]
 *     parameters: { target_agent_id: int, context_summary: string }
 */
#[Tool(
    name: 'handover',
    displayName: 'Handover',
    category: 'agent',
    description: 'Transfer the current chat to another agent that the user has pre-approved. '
               . 'Pass context_summary describing the conversation so far. '
               . 'The new task inherits the source task as its parent.',
)]
#[ToolSetting(
    key: 'allowed_target_agents',
    label: 'Allowed target agents',
    type: 'multi-select',
    description: 'Agents this agent may hand over the chat to. The LLM sees this list and may only pick from it.',
    required: true,
    // exposeToLlm: the LLM is the consumer of this allowlist. The stored
    // int[] is resolved to "Name (#id)" strings by ToolConfigSchemaInspector
    // so the model can refer to agents by name when calling this tool.
    exposeToLlm: true,
)]
#[ToolOperation(
    name: 'transfer',
    description: 'Hand the current chat over to a target agent',
    enabledByDefault: true,
    // Requires approval: the source task is closed as a side-effect.
    requiresApprovalByDefault: true,
)]
#[ToolParameter(
    name: 'target_agent_id',
    type: 'integer',
    description: 'ID of the agent to hand over to. Must be in the configured allowed_target_agents list.',
    required: true,
)]
#[ToolParameter(
    name: 'context_summary',
    type: 'string',
    description: 'A short summary of the conversation so far, written for the new agent to read as the first message.',
    required: true,
)]
final class HandoverTool extends AbstractTool
{
    public function __construct(
        private readonly HandoverServiceInterface $handover,
        private readonly ToolConfigServiceInterface $config,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $targetAgentId = (int) ($arguments['target_agent_id'] ?? 0);
        $summary       = trim((string) ($arguments['context_summary'] ?? ''));

        if ($targetAgentId <= 0) {
            return new ToolResult(false, 'target_agent_id is required.');
        }
        if ($summary === '') {
            return new ToolResult(false, 'context_summary is required.');
        }
        if ($userId === null) {
            return new ToolResult(false, 'Handover requires an authenticated user.');
        }
        if ($taskId === null) {
            return new ToolResult(false, 'Handover requires a current task context.');
        }

        $settings = $this->config->getEffectiveSettings(self::class, $agentId, $userId);
        $allowed  = $settings['allowed_target_agents'] ?? [];
        // Security: the LLM picks the target from the allowlist it sees,
        // but the tool re-validates here so a tampered payload can't reach
        // an agent the user did not pre-approve.
        if (!is_array($allowed) || !in_array($targetAgentId, array_map('intval', $allowed), true)) {
            return new ToolResult(false, 'Target agent is not in the allowed_target_agents list.');
        }

        try {
            $newTask = $this->handover->handover(
                sourceTaskId: $taskId,
                targetAgentId: $targetAgentId,
                summary: $summary,
                userId: $userId,
            );
        } catch (\InvalidArgumentException $e) {
            return new ToolResult(false, $e->getMessage());
        }

        return new ToolResult(
            success: true,
            content: "Handed over to agent #{$targetAgentId}. New task #{$newTask->id}.",
            data: [
                'handover'         => true,
                'new_task_id'      => $newTask->id,
                'target_agent_id'  => $targetAgentId,
            ],
        );
    }

    public function describeAction(array $arguments): string
    {
        $target = $arguments['target_agent_id'] ?? '?';
        return "Hand over the current chat to agent #{$target}.";
    }
}
