<?php

declare(strict_types=1);

namespace Spora\Tools;

use InvalidArgumentException;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Two operations on the same tool:
 *   - `handover`   — Close the source task and start a new task on the
 *                    target agent. The source `final_response` becomes
 *                    "Handed off to …" and the source chat ends.
 *   - `sub_agent`  — Spawn a child task on the target agent while the
 *                    parent task suspends (`status = AWAITING_SUB_AGENTS`).
 *                    When every child has terminated, the parent resumes
 *                    with each child's output appended as a `role:'tool'`
 *                    history row so the next LLM tick sees the results.
 *
 * Both share the `allowed_target_agents` multi-select so the operator
 * explicitly approves which delegated agents the LLM may pick.
 *
 * Example front-end usage (for the ToolSettingField "multi-select"):
 *   GET /api/v1/agents?select=id,name
 *
 * Example LLM-facing schema (for the tool definition):
 *   handover tool
 *     Allowed target agents: ["Legal Agent (#1)", "Sales Agent (#5)"]
 *     parameters: { op: 'handover' | 'sub_agent',
 *                   target_agent_id?: int (handover only),
 *                   agent_id?: int (sub_agent only),
 *                   prompt: string }
 */
#[Tool(
    name: 'handover',
    displayName: 'Handover',
    category: 'agent',
    description: 'Hand over a task to a pre-approved agent. '
               . '`handover` closes the source chat; `sub_agent` spawns a child task, '
               . 'waits for it to finish, then returns its output to the parent.',
    icon: 'arrow-right',
)]
#[ToolSetting(
    key: 'allowed_target_agents',
    label: 'Allowed target agents',
    type: 'multi-select',
    description: 'Agents this agent may hand over tasks to. The LLM sees this list and may only pick from it.',
    required: true,
    // exposeToLlm: the LLM is the consumer of this allowlist. The stored
    // int[] is resolved to "Name (#id)" strings by ToolConfigSchemaInspector
    // so the model can refer to agents by name when calling this tool.
    exposeToLlm: true,
)]
#[ToolOperation(
    name: 'handover',
    description: 'Hand over the source task to the target agent (closes the source chat).',
    enabledByDefault: true,
    // Requires approval: the source task is closed as a side-effect.
    requiresApprovalByDefault: true,
    discriminatorKey: 'op',
)]
#[ToolOperation(
    name: 'sub_agent',
    description: 'Spawn a child task on the target agent and wait for the result (parent stays open).',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
    discriminatorKey: 'op',
)]
#[ToolParameter(
    name: 'target_agent_id',
    type: 'integer',
    description: 'ID of the agent for the `handover` op. Must be in the configured allowed_target_agents list.',
    required: ['handover'],
)]
#[ToolParameter(
    name: 'agent_id',
    type: 'integer',
    description: 'ID of the agent for the `sub_agent` op. Must be in the configured allowed_target_agents list.',
    required: ['sub_agent'],
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Self-contained first user message for the new task. The target has NO access to '
               . 'source history, so include the goal, key facts, decisions, pending items, and any '
               . 'verbatim quotes to preserve. Anything not in this message is lost.',
    required: true,
)]
final class HandoverTool extends AbstractTool
{
    public function __construct(
        private readonly HandoverServiceInterface $handover,
        private readonly SubAgentServiceInterface $subAgent,
        private readonly ToolConfigServiceInterface $config,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $op = $this->getOperationName($arguments);

        return match ($op) {
            'sub_agent' => $this->executeSubAgent($arguments, $agentId, $userId, $taskId),
            default     => $this->executeHandover($arguments, $agentId, $userId, $taskId),
        };
    }

    private function executeHandover(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $targetAgentId = (int) ($arguments['target_agent_id'] ?? 0);
        $prompt        = trim((string) ($arguments['prompt'] ?? ''));

        $error = $this->validateHandoverInputs($targetAgentId, $prompt, $agentId, $userId, $taskId);
        if ($error !== null) {
            return new ToolResult(false, $error);
        }

        try {
            $newTask = $this->handover->handover(
                sourceTaskId: (int) $taskId,
                targetAgentId: $targetAgentId,
                summary: $prompt,
                userId: (int) $userId,
            );
        } catch (InvalidArgumentException $e) {
            return new ToolResult(false, $e->getMessage());
        }

        return new ToolResult(
            success: true,
            // The result is rendered as markdown in the chat UI, so the
            // "[New task #N](/tasks/N)" link becomes a clickable link to the
            // new task. The data payload also carries new_task_id for any
            // consumer that wants to render its own link.
            content: "Task delegated to agent #{$targetAgentId}. [New task #{$newTask->id}](/tasks/{$newTask->id}).",
            data: [
                'handover'        => true,
                'op'              => 'handover',
                'new_task_id'     => $newTask->id,
                'target_agent_id' => $targetAgentId,
            ],
        );
    }

    private function executeSubAgent(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $targetAgentId = (int) ($arguments['agent_id'] ?? 0);
        $prompt        = trim((string) ($arguments['prompt'] ?? ''));

        $error = $this->validateSubAgentInputs($targetAgentId, $prompt, $agentId, $userId, $taskId);
        if ($error !== null) {
            return new ToolResult(false, $error);
        }

        try {
            $child = $this->subAgent->spawn(
                parentTaskId: (int) $taskId,
                targetAgentId: $targetAgentId,
                prompt: $prompt,
                userId: (int) $userId,
            );
        } catch (InvalidArgumentException $e) {
            return new ToolResult(false, $e->getMessage());
        }

        return new ToolResult(
            success: true,
            // The frontend renders this as markdown, so the link opens the
            // child chat in a new tab. `spawned_sub_task_id` is read back
            // in SubAgentService to correlate the eventual child outcome
            // with the originating tool call.
            content: "Sub-agent task #{$child->id} starts on agent #{$targetAgentId}. [Task #{$child->id}](/tasks/{$child->id}).",
            data: [
                'op'                  => 'sub_agent',
                'spawned_sub_task_id' => $child->id,
                'target_agent_id'     => $targetAgentId,
            ],
        );
    }

    private function validateHandoverInputs(int $targetAgentId, string $prompt, int $agentId, ?int $userId, ?int $taskId): ?string
    {
        return match (true) {
            $targetAgentId <= 0 => 'target_agent_id is required.',
            $prompt === ''      => 'prompt is required.',
            $userId === null    => 'Handover requires an authenticated user.',
            $taskId === null    => 'Handover requires a current task context.',
            !$this->isTargetAllowed($targetAgentId, $agentId, $userId)
                => 'Target agent is not in the allowed_target_agents list.',
            default => null,
        };
    }

    private function validateSubAgentInputs(int $targetAgentId, string $prompt, int $agentId, ?int $userId, ?int $taskId): ?string
    {
        return match (true) {
            $targetAgentId <= 0 => 'agent_id is required.',
            $prompt === ''      => 'prompt is required.',
            $userId === null    => 'Sub-agent requires an authenticated user.',
            $taskId === null    => 'Sub-agent requires a current task context.',
            !$this->isTargetAllowed($targetAgentId, $agentId, $userId)
                => 'Target agent is not in the allowed_target_agents list.',
            default => null,
        };
    }

    /**
     * Security gate: the LLM picks the target from the allowlist it sees,
     * but the tool re-validates here so a tampered payload can't reach an
     * agent the user did not pre-approve.
     */
    private function isTargetAllowed(int $targetAgentId, int $agentId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        $settings = $this->config->getEffectiveSettings(self::class, $agentId, $userId);
        $allowed  = $settings['allowed_target_agents'] ?? [];

        return is_array($allowed) && in_array($targetAgentId, array_map('intval', $allowed), true);
    }

    public function describeAction(array $arguments): string
    {
        $op = $this->getOperationName($arguments);

        return match ($op) {
            'sub_agent' => sprintf(
                'Spawn a sub-agent on agent #%s and wait for its result.',
                $arguments['agent_id'] ?? '?',
            ),
            default => sprintf(
                'Hand over the task to agent #%s.',
                $arguments['target_agent_id'] ?? '?',
            ),
        };
    }
}
