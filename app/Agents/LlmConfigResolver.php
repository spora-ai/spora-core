<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\Exceptions\LlmConfigurationMissingException;
use Spora\Models\Agent;
use Spora\Services\EffortService;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Throwable;

/**
 * Resolves the effective LLM driver configuration for a given agent.
 *
 * Thin wrapper around {@see LLMConfigPreferences::getEffectiveConfigForAgent()}
 * that adds the LLM-specific shape the orchestrator expects (context_window,
 * max_tokens_output and temperature, with the defaults the legacy resolver used).
 * Delegation keeps the principal-aware resolution logic in one place.
 *
 * The reasoning effort is not part of that shape: the tick sends the effort
 * the task row carries, which is the requested level or the resolved
 * default, and that row is written by the orchestrator.
 *
 * Package-private collaborator: constructed and called only by
 * {@see Orchestrator}.
 */
final class LlmConfigResolver
{
    public function __construct(
        private readonly LLMConfigPreferences $preferences,
        private readonly ?LLMConfigService $llmConfigService = null,
        private readonly ?EffortService $effortService = null,
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

    /**
     * The level the agent runs with when nobody names one.
     *
     * The whole default chain lives in {@see EffortService::defaultFor()},
     * so the writer at task creation and the reporter on the effort
     * endpoint cannot drift apart.
     *
     * The write path asks for this, and the write path must never fail
     * because of a missing LLM configuration: a task can be created with no
     * configuration and fail at its first tick, which is a friendlier
     * failure than refusing to create it at all. That is why this returns
     * null instead of throwing.
     */
    public function resolveEffortDefault(Agent $agent): ?string
    {
        if ($this->effortService === null) {
            return null;
        }

        try {
            return $this->effortService->defaultFor($agent);
        } catch (Throwable) {
            return null;
        }
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
