<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\Exceptions\LlmConfigurationMissingException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;
use Throwable;

/**
 * Resolves the effective LLM driver configuration for a given agent.
 *
 * Extracted from {@see Orchestrator} so the orchestrator stays under the
 * SonarQube `php:S1448` method-count cap.
 *
 * Package-private collaborator: constructed and called only by
 * {@see Orchestrator}.
 */
final class LlmConfigResolver
{
    public function __construct(
        private readonly ?LLMConfigService $llmConfigService = null,
    ) {}

    /**
     * @return array{context_window: int, max_tokens_output: int, temperature: float}
     */
    public function resolveLlmConfig(Agent $agent): array
    {
        $defaults = [
            'context_window' => 128000,
            'max_tokens_output' => 4096,
            'temperature' => 0.7,
        ];

        $configId = $agent->llm_driver_config_id;

        if ($configId !== null) {
            $config = LLMDriverConfiguration::find($configId);
            if ($config !== null) {
                return [
                    'context_window' => $config->context_window ?? $defaults['context_window'],
                    'max_tokens_output' => $config->max_tokens_output ?? $defaults['max_tokens_output'],
                    'temperature' => $this->getTemperatureFromSettings($config, $defaults['temperature']),
                ];
            }
        }

        // Fall back to user preference — in async context, agent->user_id is the user context
        $preference = LLMDriverConfiguration::whereHas('userPreference', static fn($q) => $q->where('user_id', $agent->user_id))->first();
        if ($preference !== null) {
            return [
                'context_window' => $preference->context_window ?? $defaults['context_window'],
                'max_tokens_output' => $preference->max_tokens_output ?? $defaults['max_tokens_output'],
                'temperature' => $this->getTemperatureFromSettings($preference, $defaults['temperature']),
            ];
        }

        $globalDefault = LLMDriverConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->first();

        if ($globalDefault !== null) {
            return [
                'context_window' => $globalDefault->context_window ?? $defaults['context_window'],
                'max_tokens_output' => $globalDefault->max_tokens_output ?? $defaults['max_tokens_output'],
                'temperature' => $this->getTemperatureFromSettings($globalDefault, $defaults['temperature']),
            ];
        }

        throw new LlmConfigurationMissingException('No LLM configuration set for this agent. Set a preferred config or ensure a global default exists.');
    }

    private function getTemperatureFromSettings(LLMDriverConfiguration $config, float $default): float
    {
        try {
            $settings = $this->llmConfigService->decryptSettings($config->driver_class, $config->settings ?? '');
            return isset($settings['temperature']) && $settings['temperature'] !== ''
                ? (float) $settings['temperature']
                : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
