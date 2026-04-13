<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;
use Throwable;

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
        private readonly int               $llmTimeout = 300,
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

        // Fall back to global default for this user
        $defaultConfig = LLMDriverConfiguration::where('user_id', $agent->user_id)->where('is_default', true)->first();
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
            timeout: $this->llmTimeout,
        );
    }

    private function makeDriverFromConfig(LLMDriverConfiguration $config): LLMDriverInterface
    {
        $driverClass = $config->driver_class;

        if (! class_exists($driverClass)) {
            throw new RuntimeException("LLM driver class {$driverClass} does not exist.");
        }

        try {
            $settings = $this->llmConfigService->decryptSettings($config->settings ?? '');
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Failed to decrypt settings for LLM config '{$config->name}' (id={$config->id}): " . $e->getMessage(),
                0,
                $e,
            );
        }

        // Per-LLM-config timeout override; falls back to the global default.
        $timeout = isset($settings['timeout']) && $settings['timeout'] !== ''
            ? (int) $settings['timeout']
            : $this->llmTimeout;

        return new $driverClass(
            apiKey: (string) ($settings['api_key'] ?? ''),
            model: (string) ($settings['model'] ?? ''),
            baseUrl: (string) ($settings['base_url'] ?? ''),
            httpClient: \Symfony\Component\HttpClient\HttpClient::create(),
            logger: $this->logger,
            timeout: $timeout,
        );
    }
}
