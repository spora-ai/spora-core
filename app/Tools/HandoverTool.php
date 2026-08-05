<?php

declare(strict_types=1);

namespace Spora\Tools;

use InvalidArgumentException;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets the LLM hand over a task to a different agent that the user has
 * pre-approved via the `allowed_target_agents` multi-select.
 *
 * The source task is closed; a new Task is started on the target agent
 * with the LLM-supplied `prompt` as the first user message and
 * `parent_task_id` linking back to the source for UI breadcrumb rendering.
 *
 * Example front-end usage (for the ToolSettingField "multi-select"):
 *   GET /api/v1/agents?select=id,name
 *
 * Example LLM-facing schema (for the tool definition):
 *   handover tool
 *     Allowed target agents: ["Legal Agent (#1)", "Sales Agent (#5)"]
 *     parameters: { target_agent_id: int, prompt: string }
 */
#[Tool(
    name: 'handover',
    displayName: 'Handover',
    category: 'agent',
    description: 'Hand over a task to a pre-approved agent. The target starts from `prompt` '
               . 'alone (source history is not copied); the source task closes.',
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
    description: 'Hand over a task to the target agent',
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
    name: 'prompt',
    type: 'string',
    description: 'Self-contained first user message for the new task on the target agent. The target '
               . 'has NO access to source history, so include the goal, key facts, decisions, pending '
               . 'items, and any verbatim quotes to preserve. Anything not in this message is lost.',
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
        $summary       = trim((string) ($arguments['prompt'] ?? ''));

        $error = $this->validateInputs($targetAgentId, $summary, $agentId, $userId, $taskId);
        if ($error !== null) {
            return new ToolResult(false, $error);
        }

        try {
            $newTask = $this->handover->handover(
                sourceTaskId: (int) $taskId,
                targetAgentId: $targetAgentId,
                summary: $summary,
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
                'handover'         => true,
                'new_task_id'      => $newTask->id,
                'target_agent_id'  => $targetAgentId,
            ],
        );
    }

    /**
     * Returns the first validation failure message, or null when the call is allowed.
     *
     * Order matters: $userId / $taskId are nullable, so the allowlist check at the
     * end relies on the earlier guards having already short-circuited when they
     * are missing — match-true evaluates arms top-down and stops at the first hit.
     */
    private function validateInputs(int $targetAgentId, string $summary, int $agentId, ?int $userId, ?int $taskId): ?string
    {
        return match (true) {
            $targetAgentId <= 0 => 'target_agent_id is required.',
            $summary === ''     => 'prompt is required.',
            $userId === null    => 'Handover requires an authenticated user.',
            $taskId === null    => 'Handover requires a current task context.',
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
    private function isTargetAllowed(int $targetAgentId, int $agentId, int $userId): bool
    {
        $settings = $this->config->getEffectiveSettings(self::class, $agentId, $userId);
        $allowed  = $settings['allowed_target_agents'] ?? [];

        return is_array($allowed) && in_array($targetAgentId, array_map('intval', $allowed), true);
    }

    public function describeAction(array $arguments): string
    {
        $target = $arguments['target_agent_id'] ?? '?';
        return "Hand over the task to agent #{$target}.";
    }
}
