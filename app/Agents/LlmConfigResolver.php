<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\Exceptions\LlmConfigurationMissingException;
use Spora\Models\Agent;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Throwable;

/**
 * Resolves the effective LLM driver configuration for a given agent.
 *
 * Thin wrapper around {@see LLMConfigPreferences::getEffectiveConfigForAgent()}
 * that adds the LLM-specific shape the orchestrator expects (context_window,
 * max_tokens_output, temperature with the defaults from the legacy resolver).
 * Delegation keeps the principal-aware resolution logic in one place.
 *
 * Package-private collaborator: constructed and called only by
 * {@see Orchestrator}.
 */
final class LlmConfigResolver
{
    public function __construct(
        private readonly LLMConfigPreferences $preferences,
        private readonly ?LLMConfigService $llmConfigService = null,
    ) {}

    /**
     * @return array{context_window: int, max_tokens_output: int, temperature: float}
     */
    public function resolveLlmConfig(Agent $agent): array
    {
        $defaults = [
            'context_window'    => 128000,
            'max_tokens_output' => 16384,
            'temperature'       => 0.7,
        ];

        $config = $this->preferences->getEffectiveConfigForAgent($agent);
        if ($config === null) {
            throw new LlmConfigurationMissingException('No LLM configuration set for this agent. Set a preferred config or ensure a global default exists.');
        }

        return [
            'context_window'    => $config->context_window ?? $defaults['context_window'],
            'max_tokens_output' => $config->max_tokens_output ?? $defaults['max_tokens_output'],
            'temperature'       => $this->getTemperatureFromSettings($config, $defaults['temperature']),
        ];
    }

    private function getTemperatureFromSettings(\Spora\Models\LLMDriverConfiguration $config, float $default): float
    {
        if ($this->llmConfigService === null) {
            return $default;
        }

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
