<?php

declare(strict_types=1);

namespace Spora\Services\Agents;

use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigService;
use Throwable;

/**
 * Resolves per-agent settings overrides (raw, annotated, llm_configuration)
 * and parses override flags shared with operation-level resolution.
 */
final class AgentToolOverrideResolver
{
    private readonly PrincipalResolver $principalResolver;

    public function __construct(
        private readonly ToolConfigService $toolConfig,
        private readonly LLMConfigService $llmConfig,
        private readonly LLMConfigPreferences $preferences,
        private readonly AgentToolInstanceResolver $instanceResolver,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    /**
     * @return array<string, mixed>|array<string, array{value: mixed, source: string}>
     */
    public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
    {
        $agent = $this->loadAgentVisibleTo($agentId, $userId);
        if ($agent === null) {
            return [];
        }
        // llm_configuration is a special case — no registered tool class
        if ($toolClass === 'llm_configuration') {
            return $this->resolveLlmConfigurationOverride($agent);
        }
        return $rawOnly
            ? $this->resolveRawOverride($agentId, $toolClass)
            : $this->resolveAnnotatedOverride($agentId, $toolClass);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
    {
        $agent = $this->loadAgentVisibleTo($agentId, $userId);
        if ($agent === null) {
            return [];
        }

        $this->toolConfig->putAgentOverride($toolClass, $agentId, $settings);

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId);
        return $this->toolConfig->maskForApi($effective, $toolClass);
    }

    public function deleteOverride(int $agentId, int $userId, string $toolClass): void
    {
        $agent = $this->loadAgentVisibleTo($agentId, $userId);
        if ($agent === null) {
            return;
        }

        $this->toolConfig->deleteAgentOverride($toolClass, $agentId);
    }

    /**
     * Single owner/visibility check: agents no longer have a `user_id`
     * column, so the legacy `where('user_id', $userId)` predicate is
     * replaced by "is this agent's principal one the caller acts as?"
     */
    private function loadAgentVisibleTo(int $agentId, int $userId): ?Agent
    {
        return Agent::where('id', $agentId)
            ->whereIn('principal_id', $this->principalResolver->visiblePrincipalIds($userId))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLlmConfigurationOverride(Agent $agent): array
    {
        $config = $this->preferences->getEffectiveConfigForAgent($agent);
        return $config !== null ? $this->maskLlmConfig($config) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRawOverride(int $agentId, string $toolClass): array
    {
        $settings = $this->toolConfig->getRawAgentOverride($toolClass, $agentId);
        return $this->toolConfig->maskForApi($settings, $toolClass);
    }

    /**
     * @return array<string, array{value: mixed, source: string}>
     */
    private function resolveAnnotatedOverride(int $agentId, string $toolClass): array
    {
        $annotated = $this->toolConfig->getEffectiveSettingsWithSource($toolClass, $agentId);
        $passwordKeys = $this->instanceResolver->getToolPasswordKeys($toolClass);
        $result = [];
        foreach ($annotated as $key => $item) {
            $result[$key] = [
                'value'  => $this->maskAnnotatedValueIfPassword($key, $item['value'], $passwordKeys),
                'source' => $item['source'],
            ];
        }
        return $result;
    }

    private function maskAnnotatedValueIfPassword(string $key, mixed $value, array $passwordKeys): mixed
    {
        if ($value !== null && $value !== '' && in_array($key, $passwordKeys, true)) {
            return '***';
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function maskLlmConfig(LLMDriverConfiguration $config): array
    {
        try {
            $settings = $this->llmConfig->decodeSettings(
                $config->driver_class,
                $config->getRawOriginal('settings'),
            );
        } catch (Throwable) {
            $settings = [];
        }

        $drivers = $this->llmConfig->getDrivers();
        $schema = null;
        foreach ($drivers as $driver) {
            if ($driver['driver_class'] === $config->driver_class) {
                $schema = $driver['settings_schema'];
                break;
            }
        }

        return $schema !== null ? $this->llmConfig->maskForApi($settings, $schema) : $settings;
    }

    /**
     * Used by AgentToolOperationsResolver via injection through the facade.
     * Kept package-internal: callers go through the operation resolver for
     * the high-level read, this is just flag parsing.
     */
    public function extractOverrideFlag(?AgentToolOperationOverride $row, string $field): ?int
    {
        if ($row === null) {
            return null;
        }
        $raw = $row->getRawOriginal($field);
        if ($raw === null) {
            return null;
        }
        return (int) $raw === 1 ? 1 : 0;
    }

    public function parseOverrideFlag(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        if ($value === null) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
