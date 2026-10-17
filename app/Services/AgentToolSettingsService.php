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

    /**
     * `ToolStatus` is derived, not computed: it is whatever
     * `getMissingRequiredSettings()` says about the effective settings, so
     * anything merged into those settings changes it by construction. The
     * shared-settings layer is deliberately not part of that derivation — see
     * {@see self::statusResolution()} for the rule and its consequence.
     */
    private const STATUS_INCLUDES_SHARED_SETTINGS = false;

    private readonly AgentToolInstanceResolver $instanceResolver;
    private readonly AgentToolOverrideResolver $overrideResolver;
    private readonly AgentToolOperationsResolver $operationsResolver;
    private readonly PrincipalResolver $principalResolver;

    private readonly ToolConfigSchemaInspector $schema;

    public function __construct(
        private readonly ToolConfigService $toolConfig,
        LLMConfigService $llmConfig,
        ?PrincipalResolver $principalResolver = null,
        ?LLMConfigPreferences $preferences = null,
        ?ToolConfigSchemaInspector $schema = null,
    ) {
        $this->principalResolver  = $principalResolver ?? new PrincipalResolver();
        $this->schema = $schema ?? new ToolConfigSchemaInspector(principalResolver: $this->principalResolver);
        $this->instanceResolver   = new AgentToolInstanceResolver();
        $preferences             = $preferences ?? new LLMConfigPreferences();
        $this->overrideResolver   = new AgentToolOverrideResolver(
            $toolConfig,
            $llmConfig,
            $preferences,
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
            // Only when the shared group has nothing to say. An agent override
            // outranks the group, so seeding *either* value here is wrong:
            // seeding the schema default shadows the operator's shared
            // credential, and seeding the group value would drag it past the
            // status boundary, which deliberately resolves without the shared
            // layer so a tool enabled on a shared credential still reports it
            // as missing.
            $defaults = $this->toolConfig->sharedGroupHasValues($toolClass)
                ? []
                : $this->schema->getSchemaDefaults($toolClass);

            if ($defaults !== []) {
                $this->toolConfig->putAgentOverride($toolClass, $agentId, $defaults);
            }
        }

        $tool = AgentTool::where('agent_id', $agentId)->where('tool_class', $toolClass)->first();

        $missing = $this->statusResolution($toolClass, $agentId, $userId)['missing'];

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

        $missing = $this->statusResolution($toolClass, $agentId, $userId)['missing'];

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
            $missing = $this->statusResolution($toolClass, $agentId, $userId)['missing'];

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
     * Resolve a tool's settings *for status purposes*, and report what is
     * still missing. Every method on this service that produces a
     * `missing_required` list goes through here, so the shared-group rule is
     * enforced in one place rather than restated per call site.
     *
     * The shared group is excluded on purpose. It is a plugin-wide credential
     * an operator fills in once; letting it into this resolution would make
     * every tool consuming the group report `can_enable: true` with an empty
     * `missing_required` as soon as the plugin is installed, and there would
     * be no field anywhere telling the operator that the plugin's own
     * credential is still blank. The tool *runs* on the shared value — that
     * is the point of the feature — but the status keeps reporting the
     * tool's own configuration, which is what the form is actually editing.
     *
     * Reversing this is a product decision, not a code one: the moment
     * `missing_required` should honour the shared group, this constant flips
     * and `tests/Unit/Tools/SharedSettingsStatusBoundaryTest.php` is the file
     * whose assertions say so.
     *
     * @return array{missing: list<string>}
     */
    private function statusResolution(string $toolClass, int $agentId, int $userId): array
    {
        $effective = $this->toolConfig->getEffectiveSettings(
            $toolClass,
            $agentId,
            $userId,
            null,
            includeSharedSettings: self::STATUS_INCLUDES_SHARED_SETTINGS,
        );

        return ['missing' => $this->schema->getMissingRequiredSettings($toolClass, $effective)];
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
