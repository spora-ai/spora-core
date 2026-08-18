<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOverride;
use Spora\Services\Agents\AgentToolInstanceResolver;
use Spora\Services\Agents\AgentToolOperationsResolver;
use Spora\Services\Agents\AgentToolOverrideResolver;

/**
 * Tool enablement, per-agent settings overrides, and per-operation overrides.
 *
 * Extracted from {@see AgentService} so the umbrella service can stay
 * under SonarCloud's 20-method-per-class ceiling (S1448). The agent
 * existence + ownership check is inlined (a one-line Eloquent where)
 * rather than calling back into AgentService, which would create a
 * circular constructor dependency.
 *
 * In the principals-and-groups model the caller passes an explicit
 * `PrincipalResolver` and we route visibility checks through it. The
 * public method surface still takes `int $userId` so the legacy
 * controller layer (refactored separately) keeps working; internally
 * each gate routes through `PrincipalResolver::isPrincipalOwner()` or
 * `visiblePrincipalIds()` instead of the old `where('user_id', $userId)`
 * predicate.
 */
final class AgentToolSettingsService implements AgentToolSettingsServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private readonly AgentToolInstanceResolver $instanceResolver;
    private readonly AgentToolOverrideResolver $overrideResolver;
    private readonly AgentToolOperationsResolver $operationsResolver;
    private readonly PrincipalResolver $principalResolver;

    public function __construct(
        private readonly ToolConfigService $toolConfig,
        LLMConfigService $llmConfig,
        ?PrincipalResolver $principalResolver = null,
        ?LLMConfigPreferences $preferences = null,
    ) {
        $this->principalResolver  = $principalResolver ?? new PrincipalResolver();
        $this->instanceResolver   = new AgentToolInstanceResolver();
        $preferences             = $preferences ?? new LLMConfigPreferences();
        $this->overrideResolver   = new AgentToolOverrideResolver(
            $toolConfig,
            $llmConfig,
            $preferences,
            $this->instanceResolver,
            $this->principalResolver,
        );
        $this->operationsResolver = new AgentToolOperationsResolver($this->instanceResolver, $this->overrideResolver, $this->principalResolver);
    }

    public function enableTool(int $agentId, int $userId, string $toolClass): array
    {
        if (!$this->isAgentVisibleTo($agentId, $userId)) {
            return ['error' => 'NOT_FOUND'];
        }

        $existing = AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        $isIdempotent = $existing !== null;
        if ($isIdempotent) {
            return [
                'tool' => [
                    'tool_class' => $existing->tool_class,
                    'tool_name'  => $existing->tool_name,
                ],
                'is_idempotent' => true,
            ];
        }

        Capsule::table('agent_tools')->insert([
            'agent_id'   => $agentId,
            'tool_class' => $toolClass,
            'tool_name'  => $this->instanceResolver->resolveToolName($toolClass),
            'created_at' => date(self::DATETIME_FORMAT),
            'updated_at' => date(self::DATETIME_FORMAT),
        ]);

        $globalSettings = $this->toolConfig->getGlobalSettings($toolClass);
        $hasAgentOverride = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->exists();

        if ($globalSettings === [] && !$hasAgentOverride) {
            $defaults = $this->toolConfig->getSchemaDefaults($toolClass);
            if ($defaults !== []) {
                $this->toolConfig->putAgentOverride($toolClass, $agentId, $defaults);
            }
        }

        $tool = AgentTool::where('agent_id', $agentId)->where('tool_class', $toolClass)->first();

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId, $userId, null);
        $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

        $result = [
            'tool' => [
                'tool_class' => $tool->tool_class,
                'tool_name'  => $tool->tool_name,
            ],
        ];
        if ($missing !== []) {
            $result['warning'] = 'Required settings are missing. The tool may not work until credentials are configured.';
            $result['missing_required'] = $missing;
        }

        return $result;
    }

    public function disableTool(int $agentId, int $userId, string $toolClass): void
    {
        if (!$this->isAgentVisibleTo($agentId, $userId)) {
            return;
        }

        AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->delete();
    }

    public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
    {
        if (!$this->isAgentVisibleTo($agentId, $userId)) {
            return null;
        }

        $isEnabled = AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->exists();

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId, $userId, null);
        $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

        return [
            'tool_class'       => $toolClass,
            'tool_name'        => $this->instanceResolver->resolveToolName($toolClass),
            'is_enabled'       => $isEnabled,
            'missing_required' => $missing,
            'can_enable'       => $missing === [],
        ];
    }

    public function getAllToolsStatus(int $agentId, int $userId): ?array
    {
        if (!$this->isAgentVisibleTo($agentId, $userId)) {
            return null;
        }

        $toolClasses = $this->toolConfig->getRegisteredToolClasses();
        $statuses = [];

        $enabledTools = AgentTool::where('agent_id', $agentId)
            ->pluck('tool_class')
            ->flip()
            ->toArray();

        foreach ($toolClasses as $toolClass) {
            $isEnabled = isset($enabledTools[$toolClass]);
            $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId, $userId, null);
            $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

            $statuses[] = [
                'tool_class'       => $toolClass,
                'tool_name'        => $this->instanceResolver->resolveToolName($toolClass),
                'is_enabled'       => $isEnabled,
                'missing_required' => $missing,
                'can_enable'       => $missing === [],
            ];
        }

        return $statuses;
    }

    public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
    {
        return $this->overrideResolver->getOverride($agentId, $userId, $toolClass, $rawOnly);
    }

    public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
    {
        return $this->overrideResolver->putOverride($agentId, $userId, $toolClass, $settings);
    }

    public function deleteOverride(int $agentId, int $userId, string $toolClass): void
    {
        $this->overrideResolver->deleteOverride($agentId, $userId, $toolClass);
    }

    public function getToolsOperations(int $agentId, int $userId): ?array
    {
        return $this->operationsResolver->getToolsOperations($agentId, $userId);
    }

    public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
    {
        return $this->operationsResolver->getOperationOverride($agentId, $userId, $toolClass, $operation);
    }

    public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
    {
        return $this->operationsResolver->patchOperationOverride($agentId, $userId, $toolClass, $operation, $data);
    }

    /**
     * Single seam for "can this user see this agent?" — the principals
     * axis means visibility tracks every principal the caller can act
     * as (own user-principal + group-principals for their groups).
     */
    private function isAgentVisibleTo(int $agentId, int $userId): bool
    {
        return Agent::where('id', $agentId)
            ->whereIn('principal_id', $this->principalResolver->visiblePrincipalIds($userId))
            ->exists();
    }
}
