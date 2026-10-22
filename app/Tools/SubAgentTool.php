<?php

declare(strict_types=1);

namespace Spora\Tools;

use InvalidArgumentException;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Three operations on the same `sub_agent` tool:
 *   - `sub_agent`         — Spawn a child task on the target agent while the
 *                           parent task suspends (`status = AWAITING_SUB_AGENTS`).
 *                           When every child has terminated, the parent resumes
 *                           with each child's output appended as a `role:'tool'`
 *                           history row so the next LLM tick sees the results.
 *                           Declared first so the runtime fallback when the
 *                           `op` discriminator is omitted lands here.
 *   - `sub_agent_continue` — Continue an existing child task with a follow-up
 *                           message and wait for its next answer. The child
 *                           keeps its earlier conversation, so the follow-up
 *                           does not have to be self-contained.
 *   - `handover`          — Delegate to the target agent, which continues in
 *                           the background. The source chat stays open and
 *                           ends on its own terms; the target's answer never
 *                           comes back.
 *
 * `sub_agent` and `handover` share a single `target_agent_id` parameter. The
 * schema's `enum` is populated from `allowed_target_agents` using "Name (#id)"
 * labels so the LLM picks agents by name; the tool parses the label back to
 * an int at execute time. `allowed_target_agents` is intra-principal — the
 * picker surfaces only same-principal agents, the tool re-validates the
 * principal match at runtime (`isTargetAllowed()`), and the service layer
 * enforces a final `callerControlsPrincipal` check.
 */
#[Tool(
    name: 'sub_agent',
    displayName: 'Sub-Agent',
    category: 'agent',
    description: 'Delegate to another agent: `sub_agent` (default — spawns a child task and waits for the answer), `sub_agent_continue` (follows up on a sub-agent task you already started, and waits for the new answer), or `handover` (the target works in the background and you get no answer back). See the sub-agent skill — for `sub_agent` the prompt is the ENTIRETY of what the target sees.',
    icon: 'arrow-right',
    recommendsSkills: ['sub-agent'],
)]
#[ToolSetting(
    key: 'allowed_target_agents',
    label: 'Allowed target agents',
    type: 'multi-select',
    description: 'Agents this agent may hand over tasks to. The LLM sees this list and may only pick from it.',
    required: true,
    // scope: 'principal' hides the picker on the admin operator-defaults
    // page where no principal context doesn't exist.
    scope: 'principal',
    // Stored int[] is resolved to "Name (#id)" strings by ToolConfigSchemaInspector
    // so the LLM can refer to agents by name when calling this tool.
    exposeToLlm: true,
)]
#[ToolOperation(
    name: 'sub_agent',
    description: 'Spawn a child task on the target agent and wait for its result. Default go-to for delegation.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
    discriminatorKey: 'op',
)]
#[ToolOperation(
    name: 'sub_agent_continue',
    description: 'Continue an existing sub-agent task with a follow-up message and wait for the new answer. The target keeps its earlier context.',
    enabledByDefault: false,
    requiresApprovalByDefault: false,
    discriminatorKey: 'op',
)]
#[ToolOperation(
    name: 'handover',
    description: 'Delegate to the target agent, which continues in the background. This chat stays open — you get no answer back.',
    enabledByDefault: false,
    requiresApprovalByDefault: false,
    discriminatorKey: 'op',
)]
#[ToolParameter(
    name: 'target_agent_id',
    type: 'string',
    description: 'Label of the target agent. Must be in the configured allowed_target_agents list. '
               . 'Format: "Name (#id)" — e.g. "Legal Agent (#11)".',
    required: ['handover', 'sub_agent'],
    enumSource: 'allowed_target_agents',
)]
#[ToolParameter(
    name: 'task_id',
    type: 'string',
    description: 'Task id of the sub-agent task to continue — the number from the earlier sub_agent result ("Sub-agent task #777", also the /tasks/777 link). Only sub-agent tasks started by this conversation can be continued.',
    required: ['sub_agent_continue'],
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'For `sub_agent` and `handover`: a self-contained first user message for the new task. The target has NO access to '
               . 'source history, so include the goal, key facts, decisions, pending items, and any '
               . 'verbatim quotes to preserve. Anything not in this message is lost. '
               . 'For `sub_agent_continue`: a follow-up message for a task that already has the earlier conversation — say only what the target cannot see.',
    required: true,
)]
final class SubAgentTool extends AbstractTool
{
    /** The model's failure text when it sends no usable prompt. */
    private const PROMPT_REQUIRED = 'prompt is required.';

    /** The model's failure text when the target is outside the allowlist. */
    private const TARGET_NOT_ALLOWED = 'Target agent is not in the allowed_target_agents list.';

    public function __construct(
        private readonly HandoverServiceInterface $handover,
        private readonly SubAgentServiceInterface $subAgent,
        private readonly ToolConfigServiceInterface $config,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        $userId = $context?->ownerUserId;
        $op = $this->getOperationName($arguments);

        // sub_agent is declared first, so `HasOperations::getOperationName()`
        // also falls back to sub_agent when the `op` discriminator is
        // omitted — keep the match shape in lockstep with the attribute
        // order so the runtime default matches the skill's "default
        // go-to" claim.
        return match ($op) {
            'handover'           => $this->executeHandover($arguments, $agentId, $userId, $taskId),
            'sub_agent_continue' => $this->executeContinue($arguments, $agentId, $userId, $taskId),
            default              => $this->executeSubAgent($arguments, $agentId, $userId, $taskId),
        };
    }

    private function executeHandover(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $targetAgentId = $this->resolveTargetAgentId($arguments['target_agent_id'] ?? null);
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
            content: "Delegated to agent #{$targetAgentId}, which continues in the background. "
                . "[New task #{$newTask->id}](/tasks/{$newTask->id}). "
                . 'Tell the user who is handling it and end your turn.',
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
        $targetAgentId = $this->resolveTargetAgentId($arguments['target_agent_id'] ?? null);
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

    private function executeContinue(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $childTaskId = $this->resolveChildTaskId($arguments['task_id'] ?? null);
        $prompt      = trim((string) ($arguments['prompt'] ?? ''));

        $error = $this->validateContinueInputs($childTaskId, $prompt, $userId, $taskId);
        $child = $error === null ? Task::find($childTaskId) : null;

        if ($error === null && $child === null) {
            $error = "Sub-agent task #{$childTaskId} not found.";
        }

        // Past that branch `$error === null` implies the child was found,
        // so the allowlist check below always has an agent to check.
        //
        // The operator's trust boundary applies to a continuation too: the
        // child's agent has to be on the allowlist, and the LLM cannot
        // name a task that lives on an agent the operator never approved.
        if ($error === null && !$this->isTargetAllowed((int) $child->agent_id, $agentId, $userId)) {
            $error = self::TARGET_NOT_ALLOWED;
        }

        if ($error !== null) {
            return new ToolResult(false, $error);
        }

        try {
            $continued = $this->subAgent->continueChild(
                parentTaskId: (int) $taskId,
                childTaskId: $childTaskId,
                prompt: $prompt,
                userId: (int) $userId,
            );
        } catch (InvalidArgumentException $e) {
            return new ToolResult(false, $e->getMessage());
        }

        return new ToolResult(
            success: true,
            content: "Sub-agent task #{$continued->id} continues on agent #{$continued->agent_id}. [Task #{$continued->id}](/tasks/{$continued->id}).",
            data: [
                'op'                   => 'sub_agent_continue',
                // Keeps the frontend widget and the resume correlation working.
                'spawned_sub_task_ids' => [$continued->id],
                'continued_task_id'    => $continued->id,
                'target_agent_id'      => (int) $continued->agent_id,
            ],
        );
    }

    private function validateHandoverInputs(int $targetAgentId, string $prompt, int $agentId, ?int $userId, ?int $taskId): ?string
    {
        return match (true) {
            $targetAgentId <= 0 => 'target_agent_id is required.',
            $prompt === ''      => self::PROMPT_REQUIRED,
            $userId === null    => 'Handover requires an authenticated user.',
            $taskId === null    => 'Handover requires a current task context.',
            !$this->isTargetAllowed($targetAgentId, $agentId, $userId)
                => self::TARGET_NOT_ALLOWED,
            default => null,
        };
    }

    private function validateSubAgentInputs(int $targetAgentId, string $prompt, int $agentId, ?int $userId, ?int $taskId): ?string
    {
        return match (true) {
            $targetAgentId <= 0 => 'target_agent_id is required.',
            $prompt === ''      => self::PROMPT_REQUIRED,
            $userId === null    => 'Sub-agent requires an authenticated user.',
            $taskId === null    => 'Sub-agent requires a current task context.',
            !$this->isTargetAllowed($targetAgentId, $agentId, $userId)
                => self::TARGET_NOT_ALLOWED,
            default => null,
        };
    }

    private function validateContinueInputs(int $childTaskId, string $prompt, ?int $userId, ?int $taskId): ?string
    {
        return match (true) {
            $childTaskId <= 0 => 'task_id is required.',
            $prompt === ''    => self::PROMPT_REQUIRED,
            $userId === null  => 'Sub-agent continuation requires an authenticated user.',
            $taskId === null  => 'Sub-agent continuation requires a current task context.',
            default => null,
        };
    }

    /**
     * Parse the LLM-facing `target_agent_id` value back to an int.
     *
     * The schema's `enum` is the resolved "Name (#id)" label so the
     * wire format is the label string. Accepts ints too as a back-compat
     * for legacy callers/tests. Unparseable input returns `0`, which
     * the validators report as `target_agent_id is required.`
     */
    private function resolveTargetAgentId(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== ''
            && (preg_match('/.*\(#(\d+)\)\s*$/', $raw, $m)
                || preg_match('/^#(\d+)\s*$/', $raw, $m)
                || ctype_digit($raw))
        ) {
            return (int) ($m[1] ?? $raw);
        }
        return 0;
    }

    /**
     * Parse the LLM-facing `task_id` value back to an int.
     *
     * The schema declares it as a string because LLM drivers emit numerics
     * as strings, and `SchemaValidator` only accepts an int for an
     * `integer` type. Unparseable input returns `0`, which the validator
     * reports as `task_id is required.`
     */
    private function resolveChildTaskId(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit(trim($raw))) {
            return (int) trim($raw);
        }
        return 0;
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
            'handover' => sprintf(
                'Delegate to agent #%s in the background.',
                $arguments['target_agent_id'] ?? '?',
            ),
            'sub_agent_continue' => sprintf(
                'Continue sub-agent task #%s.',
                $arguments['task_id'] ?? '?',
            ),
            default => sprintf(
                'Spawn a sub-agent on agent #%s and wait for its result.',
                $arguments['target_agent_id'] ?? '?',
            ),
        };
    }
}
