<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionClass;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOverride;
use Spora\Services\Agents\AgentToolInstanceResolver;
use Spora\Services\Agents\AgentToolOperationsResolver;
use Spora\Services\Agents\AgentToolOverrideResolver;
use Spora\Tools\Attributes\Tool;

/**
 * Tool enablement, per-agent settings overrides, and per-operation overrides.
 *
 * Extracted from {@see AgentService} so the umbrella service can stay
 * under SonarCloud's 20-method-per-class ceiling (S1448). The agent
 * existence + ownership check is inlined (a one-line Eloquent where)
 * rather than calling back into AgentService, which would create a
 * circular constructor dependency.
 */
final class AgentToolSettingsService implements AgentToolSettingsServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private readonly AgentToolInstanceResolver $instanceResolver;
    private readonly AgentToolOverrideResolver $overrideResolver;
    private readonly AgentToolOperationsResolver $operationsResolver;

    public function __construct(
        private readonly ToolConfigService $toolConfig,
        LLMConfigService $llmConfig,
    ) {
        $this->instanceResolver   = new AgentToolInstanceResolver();
        $this->overrideResolver   = new AgentToolOverrideResolver($toolConfig, $llmConfig, $this->instanceResolver);
        $this->operationsResolver = new AgentToolOperationsResolver($this->instanceResolver, $this->overrideResolver);
    }

    public function enableTool(int $agentId, int $userId, string $toolClass): array
    {
        if (Agent::where('id', $agentId)->where('user_id', $userId)->first() === null) {
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

        // Seed schema defaults if no global config AND no agent override exists
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

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId);
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
        if (Agent::where('id', $agentId)->where('user_id', $userId)->first() === null) {
            return;
        }

        AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->delete();
    }

    public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
    {
        if (Agent::where('id', $agentId)->where('user_id', $userId)->first() === null) {
            return null;
        }

        $isEnabled = AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->exists();

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId, $userId);
        $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

        return [
            'tool_class'       => $toolClass,
            'tool_name'        => $this->resolveToolName($toolClass),
            'is_enabled'       => $isEnabled,
            'missing_required' => $missing,
            'can_enable'       => $missing === [],
        ];
    }

    public function getAllToolsStatus(int $agentId, int $userId): ?array
    {
        if (Agent::where('id', $agentId)->where('user_id', $userId)->first() === null) {
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
            $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId, $userId);
            $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

            $statuses[] = [
                'tool_class'       => $toolClass,
                'tool_name'        => $this->resolveToolName($toolClass),
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

    private function resolveToolName(string $toolClass): string
    {
        if (!class_exists($toolClass)) {
            $parts = explode('\\', $toolClass);
            return end($parts) ?: $toolClass;
        }
        $reflection = new ReflectionClass($toolClass);
        $toolAttrs = $reflection->getAttributes(Tool::class);
        if ($toolAttrs === []) {
            return $reflection->getShortName();
        }
        return $toolAttrs[0]->newInstance()->name;
    }
}
