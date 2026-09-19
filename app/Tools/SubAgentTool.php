<?php

declare(strict_types=1);

namespace Spora\Tools;

use InvalidArgumentException;
use Spora\Models\Agent;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Two operations on the same `sub_agent` tool:
 *   - `handover`   — Close the source task and start a new task on the
 *                    target agent. The source `final_response` becomes
 *                    "Handed off to …" and the source chat ends.
 *   - `sub_agent`  — Spawn a child task on the target agent while the
 *                    parent task suspends (`status = AWAITING_SUB_AGENTS`).
 *                    When every child has terminated, the parent resumes
 *                    with each child's output appended as a `role:'tool'`
 *                    history row so the next LLM tick sees the results.
 *
 * Both ops share a single `target_agent_id: integer` parameter so the
 * LLM-facing schema reads as one consistent field — no duplicate
 * "handover vs sub_agent" id params for the model to keep straight. The
 * `allowed_target_agents` multi-select (operator-approved allowlist) is
 * intra-principal: the LLM may only target agents owned by the same
 * `principal_id` as the source. The picker surfaces only same-principal
 * agents, the tool re-validates the principal match at runtime
 * (`isTargetAllowed()`), and the service layer
 * (`HandoverService` / `SubAgentService`) enforces a final
 * `callerControlsPrincipal` check.
 *
 * `allowed_target_agents` declares `scope: 'principal'` so the picker
 * is hidden on the admin operator-defaults page where no principal
 * context exists. Existing global rows still cascade down to users
 * without overrides; the runtime LLM-side filter in
 * {@see \Spora\Services\ToolConfigSchemaInspector::fetchAgentNameMap()}
 * restricts the LLM-visible list to the source agent's principal, so
 * any foreign ids in a stale global degrade to "#id" placeholders.
 *
 * Example LLM-facing schema (for the tool definition):
 *   sub_agent tool
 *     Allowed target agents: ["Legal Agent (#11)", "Sales Agent (#4)"]
 *     parameters: { op: 'handover' | 'sub_agent',
 *                   target_agent_id: int (enum=[11,4], description
 *                     suffix: "Allowed values: Legal Agent (#11),
 *                     Sales Agent (#4)"),
 *                   prompt: string }
 *
 * `enumSource: 'allowed_target_agents'` on `target_agent_id` ties the
 * parameter's LLM-side `enum` and description suffix to the allowlist
 * setting at schema-build time (see
 * {@see \Spora\Tools\Schema\ToolParameterSchemaBuilder}). The names flow
 * through the same `ToolConfigSchemaInspector::fetchAgentNameMap()` path
 * as the `[Effective Configuration]` block, so foreign ids still degrade
 * to "#id" placeholders — the cross-tenant guard added for the block
 * covers the parameter suffix too.
 */
#[Tool(
    name: 'sub_agent',
    displayName: 'Sub-Agent',
    category: 'agent',
    description: 'Hand off a task or spawn a sub-agent. '
               . '`handover` closes the source chat and starts a new task on the target agent; '
               . '`sub_agent` spawns a child task on the target agent, waits for it to finish, '
               . 'then returns its output to the parent.',
    icon: 'arrow-right',
)]
#[ToolSetting(
    key: 'allowed_target_agents',
    label: 'Allowed target agents',
    type: 'multi-select',
    description: 'Agents this agent may hand over tasks to. The LLM sees this list and may only pick from it.',
    required: true,
    // scope: 'principal' hides the picker on the admin operator-defaults
    // page where no principal context exists. The same picker renders
    // under Settings → Tools (user-principal), Groups → Tools (group-
    // principal), and the agent's Tools tab (the agent's principal).
    scope: 'principal',
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
    description: 'ID of the target agent. Must be in the configured allowed_target_agents list.',
    required: ['handover', 'sub_agent'],
    enumSource: 'allowed_target_agents',
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Self-contained first user message for the new task. The target has NO access to '
               . 'source history, so include the goal, key facts, decisions, pending items, and any '
               . 'verbatim quotes to preserve. Anything not in this message is lost.',
    required: true,
)]
final class SubAgentTool extends AbstractTool
{
    public function __construct(
        private readonly HandoverServiceInterface $handover,
        private readonly SubAgentServiceInterface $subAgent,
        private readonly ToolConfigServiceInterface $config,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
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
        $targetAgentId = (int) ($arguments['target_agent_id'] ?? 0);
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
            // child chat in a new tab. `spawned_sub_task_ids` is read back
            // in SubAgentService to correlate the eventual child outcome
            // with the originating tool call. The plural array shape keeps
            // the schema identical whether the batch contains one or many
            // sub_agent ops, so the frontend does not need a special case
            // for the single-child case.
            content: "Sub-agent task #{$child->id} starts on agent #{$targetAgentId}. [Task #{$child->id}](/tasks/{$child->id}).",
            data: [
                'op'                   => 'sub_agent',
                'spawned_sub_task_ids' => [$child->id],
                'target_agent_id'      => $targetAgentId,
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
            $targetAgentId <= 0 => 'target_agent_id is required.',
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
     *
     * Defense in depth: after the allowlist hit, cross-check that the
     * source and target share a `principal_id`. A foreign id that slipped
     * into the stored allowlist (manual override, tampered payload,
     * copy-paste error) is rejected here in addition to the service-level
     * `callerControlsPrincipal` check. Fail-closed when the source agent
     * cannot be loaded.
     */
    private function isTargetAllowed(int $targetAgentId, int $agentId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }
        if (!$this->isTargetOnAllowlist($targetAgentId, $agentId, $userId)) {
            return false;
        }
        return $this->sharePrincipal($targetAgentId, $agentId);
    }

    private function isTargetOnAllowlist(int $targetAgentId, int $agentId, int $userId): bool
    {
        $settings = $this->config->getEffectiveSettings(self::class, $agentId, $userId);
        $allowed  = $settings['allowed_target_agents'] ?? [];
        return is_array($allowed)
            && in_array($targetAgentId, array_map('intval', $allowed), true);
    }

    private function sharePrincipal(int $targetAgentId, int $agentId): bool
    {
        $source = Agent::find($agentId);
        $target = Agent::find($targetAgentId);
        if ($source === null || $target === null) {
            return false;
        }
        return (int) $source->principal_id === (int) $target->principal_id;
    }

    public function describeAction(array $arguments): string
    {
        $op = $this->getOperationName($arguments);

        return match ($op) {
            'sub_agent' => sprintf(
                'Spawn a sub-agent on agent #%s and wait for its result.',
                $arguments['target_agent_id'] ?? '?',
            ),
            default => sprintf(
                'Hand over the task to agent #%s.',
                $arguments['target_agent_id'] ?? '?',
            ),
        };
    }
}
