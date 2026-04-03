<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Spora\Models\Agent;
use Symfony\Component\HttpClient\HttpClient;

use Spora\Services\ToolConfigService;
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
    ) {}

    public function makeFromAgent(Agent $agent): LLMDriverInterface
    {
        $provider = $agent->llm_provider ?? 'openai_compatible';
        $model    = $agent->llm_model    ?? 'gpt-4o';
        $httpClient = HttpClient::create();
        $settings   = $this->toolConfigService->getEffectiveSettings(LLMConfiguration::class, (int) $agent->id);

        if ($provider === 'anthropic') {
            $apiKey  = (string) ($settings['anthropic_api_key'] ?? '');
            $baseUrl = $agent->llm_base_url ?? 'https://api.anthropic.com/v1/messages';

            return new AnthropicCompatibleDriver(
                apiKey:     $apiKey,
                model:      $model,
                baseUrl:    $baseUrl,
                httpClient: $httpClient,
                logger:     $this->logger,
            );
        }

        // Default: openai_compatible (also covers Ollama, Groq, LM Studio, Azure, etc.)
        $apiKey  = (string) ($settings['openai_api_key'] ?? '');
        $baseUrl = $agent->llm_base_url ?? 'https://api.openai.com/v1';

        return new OpenAICompatibleDriver(
            apiKey:     $apiKey,
            model:      $model,
            baseUrl:    $baseUrl,
            httpClient: $httpClient,
            logger:     $this->logger,
        );
    }
}
