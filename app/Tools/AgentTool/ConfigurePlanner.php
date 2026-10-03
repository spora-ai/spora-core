<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Applies a validated `configure_tools` plan.
 *
 * Parsing lives in {@see ConfigurePlanParser}: the two halves have different
 * dependencies and different failure modes — every refusal happens in the parser
 * before a single write, so a rejected payload cannot have landed anything.
 *
 * `enableTool` signals an unowned agent by returning `['error' => 'NOT_FOUND']`
 * rather than throwing, and discarding that is how a success-shaped response can
 * hide a write that never happened. It is surfaced instead. The check is
 * per-agent and therefore identical for every step, so bailing on the first one
 * leaves nothing half-applied.
 */
final class ConfigurePlanner
{
    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';

    private const ENABLE_NOT_FOUND = 'NOT_FOUND';

    private readonly ConfigurePlanParser $parser;

    public function __construct(
        private readonly AgentToolSettingsServiceInterface $toolSettings,
        ?SkillProviderRegistry $skills = null,
    ) {
        $this->parser = new ConfigurePlanParser($skills);
    }

    /**
     * Validate the submitted entries into a plan, or refuse the whole call.
     *
     * @param  mixed $entries
     * @param  int|null $principalId The principal whose visible skills an
     *        `allowed_skills` write may name. Null resolves no principal, so a
     *        provider that scopes by one sees nothing and every name is refused.
     * @return list<array{tool_class: string, enable: bool|null, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}>|ToolResult
     */
    public function buildPlan(mixed $entries, ?int $principalId = null): array|ToolResult
    {
        return $this->parser->buildPlan($entries, $principalId);
    }

    public function apply(int $agentId, int $userId, array $plan): ToolResult|null
    {
        foreach ($plan as $step) {
            $failure = $this->applyStep($agentId, $userId, $step);
            if ($failure instanceof ToolResult) {
                return $failure;
            }
        }
        return null;
    }

    /**
     * One plan step: enablement first, then per-operation overrides, then settings.
     *
     * Split out of {@see apply()} so the loop stays a loop. The enablement write
     * can be the one that discovers the target is unwritable, and it is checked
     * before anything else in the step so a step never half-applies.
     *
     * @param  array{tool_class: string, enable: bool|null, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>} $step
     * @return ToolResult|null
     */
    private function applyStep(int $agentId, int $userId, array $step): ToolResult|null
    {
        $enablement = $this->applyEnablement($agentId, $userId, $step);
        if ($enablement instanceof ToolResult) {
            return $enablement;
        }

        foreach ($step['operations'] as $op) {
            $this->toolSettings->patchOperationOverride(
                $agentId,
                $userId,
                $step['tool_class'],
                $op['name'],
                [
                    'enabled'                   => $op['enabled'] ? 1 : 0,
                    'default_requires_approval' => $op['auto_approve'] ? 0 : 1,
                ],
            );
        }

        if ($step['settings'] !== []) {
            $this->toolSettings->putOverride($agentId, $userId, $step['tool_class'], $step['settings']);
        }

        return null;
    }

    /**
     * @param  array{tool_class: string, enable: bool|null} $step
     * @return ToolResult|null
     */
    private function applyEnablement(int $agentId, int $userId, array $step): ToolResult|null
    {
        if ($step['enable'] === false) {
            $this->toolSettings->disableTool($agentId, $userId, $step['tool_class']);
            return null;
        }
        if ($step['enable'] !== true) {
            return null;
        }

        $written = $this->toolSettings->enableTool($agentId, $userId, $step['tool_class']);

        return ($written['error'] ?? null) === self::ENABLE_NOT_FOUND
            ? $this->notVisibleFailure()
            : null;
    }

    private function notVisibleFailure(): ToolResult
    {
        return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX
            . 'the target agent is not visible to this user, so no tool was changed.');
    }

    /**
     * @param  mixed $entry
     * @return array{tool_class: string, enable: bool|null, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}|ToolResult
     */
}
