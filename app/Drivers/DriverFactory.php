<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Spora\Models\Agent;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Resolves the correct LLM driver for a given Agent.
 *
 * Provider and model are read from the Agent row (set by the user in Agent Settings).
 * API keys are read from the application config, which maps to SPORA_OPENAI_API_KEY /
 * SPORA_ANTHROPIC_API_KEY environment variables (or config.php for shared hosting).
 */
class DriverFactory
{
    public function __construct(
        private readonly array $config,
    ) {}

    public function makeFromAgent(Agent $agent): LLMDriverInterface
    {
        $provider = $agent->llm_provider ?? 'openai_compatible';
        $model    = $agent->llm_model    ?? 'gpt-4o';
        $baseUrl  = $agent->llm_base_url ?? 'https://api.openai.com/v1';

        $httpClient = HttpClient::create();

        if ($provider === 'anthropic') {
            $apiKey = (string) ($this->config['anthropic_api_key'] ?? '');

            return new AnthropicDriver(
                apiKey:     $apiKey,
                model:      $model,
                httpClient: $httpClient,
            );
        }

        // Default: openai_compatible (also covers Ollama, Groq, LM Studio, Azure, etc.)
        $apiKey = (string) ($this->config['openai_api_key'] ?? '');

        return new OpenAICompatibleDriver(
            apiKey:     $apiKey,
            model:      $model,
            baseUrl:    $baseUrl,
            httpClient: $httpClient,
        );
    }
}
