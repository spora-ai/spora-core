<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;

/**
 * Builds the correct LLM driver for a given Agent.
 *
 * Resolution order:
 *   1. Agent has llm_driver_config_id → use that config
 *   2. Otherwise → use the global default LLMDriverConfiguration
 *   3. If neither exists → fall back to OpenAICompatibleDriver with minimal defaults
 */
class DriverFactory
{
    public function __construct(
        private readonly LoggerInterface    $logger,
        private readonly LLMConfigService $llmConfigService,
    ) {}

    public function makeFromAgent(Agent $agent): LLMDriverInterface
    {
        // Try agent-specific config first
        $configId = $agent->llm_driver_config_id;

        if ($configId !== null) {
            $config = LLMDriverConfiguration::find($configId);
            if ($config !== null) {
                return $this->makeDriverFromConfig($config);
            }
        }

        // Fall back to global default
        $defaultConfig = LLMDriverConfiguration::where('is_default', true)->first();
        if ($defaultConfig !== null) {
            return $this->makeDriverFromConfig($defaultConfig);
        }

        // Ultimate fallback: OpenAICompatibleDriver with empty settings
        $this->logger->warning('No LLMDriverConfiguration found, using fallback OpenAI driver.');

        return new OpenAICompatibleDriver(
            apiKey: '',
            model: 'gpt-4o',
            baseUrl: 'https://api.openai.com/v1',
            httpClient: \Symfony\Component\HttpClient\HttpClient::create(),
            logger: $this->logger,
        );
    }

    private function makeDriverFromConfig(LLMDriverConfiguration $config): LLMDriverInterface
    {
        $driverClass = $config->driver_class;

        if (! class_exists($driverClass)) {
            throw new RuntimeException("LLM driver class {$driverClass} does not exist.");
        }

        $settings = $this->llmConfigService->decryptSettings($config->settings);

        return new $driverClass(
            apiKey: (string) ($settings['api_key'] ?? ''),
            model: (string) ($settings['model'] ?? ''),
            baseUrl: (string) ($settings['base_url'] ?? ''),
            httpClient: \Symfony\Component\HttpClient\HttpClient::create(),
            logger: $this->logger,
        );
    }
}
