<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Spora\Models\Agent;
use Symfony\Component\HttpClient\HttpClient;

use Spora\Services\ToolConfigService;

/**
 * Resolves the correct LLM driver for a given Agent.
 *
 * Provider and model are read from the Agent row (set by the user in Agent Settings).
 * API keys are securely read from the database via ToolConfigService using LLMConfiguration.
 */
class DriverFactory
{
    public function __construct(
        private readonly ToolConfigService $toolConfigService,
    ) {}

    public function makeFromAgent(Agent $agent): LLMDriverInterface
    {
        $provider = $agent->llm_provider ?? 'openai_compatible';
        $model    = $agent->llm_model    ?? 'gpt-4o';
        $baseUrl  = $agent->llm_base_url ?? 'https://api.openai.com/v1';

        $httpClient = HttpClient::create();
        $settings   = $this->toolConfigService->getEffectiveSettings(LLMConfiguration::class, (int) $agent->id);

        if ($provider === 'anthropic') {
            $apiKey = (string) ($settings['anthropic_api_key'] ?? '');

            return new AnthropicDriver(
                apiKey:     $apiKey,
                model:      $model,
                httpClient: $httpClient,
            );
        }

        // Default: openai_compatible (also covers Ollama, Groq, LM Studio, Azure, etc.)
        $apiKey = (string) ($settings['openai_api_key'] ?? '');

        return new OpenAICompatibleDriver(
            apiKey:     $apiKey,
            model:      $model,
            baseUrl:    $baseUrl,
            httpClient: $httpClient,
        );
    }
}
