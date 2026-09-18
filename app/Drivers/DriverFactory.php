<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Psr\Log\LoggerInterface;
use Spora\Agents\Exceptions\LlmConfigurationMissingException;
use Spora\Drivers\Exceptions\DriverClassNotFoundException;
use Spora\Drivers\Exceptions\LLMConfigDecryptFailedException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Throwable;

/**
 * Builds the correct LLM driver for a given Agent.
 *
 * Resolution order:
 *   1. Agent has llm_driver_config_id → use that config
 *   2. Otherwise → use the global default LLMDriverConfiguration
 *   3. If neither exists → throw {@see LlmConfigurationMissingException}
 *
 * Step 3 is a hard stop: the previous "fall back to an empty-key
 * OpenAI driver" silently punted the request to api.openai.com with
 * `apiKey: ''`, which surfaced as an upstream 401 instead of the
 * operator-visible "no LLM configured" error the operator actually
 * needs. TickPhaseRunner::prepareTickContext() catches the throw
 * and ErrorClassifier::markTaskNoLlmConfiguration() writes the
 * friendly NO_LLM_CONFIGURATION message to the task row.
 *
 * The `supports_image_input` toggle is read from the decoded settings blob.
 * The frontend round-trips booleans as the strings `"true"`/`"false"`,
 * so a value of `"false"` (or any falsy string) decodes to `false`, and
 * the absence of the key decodes to `null` so the driver falls back to
 * the model-name heuristic for legacy rows.
 */
class DriverFactory
{
    private readonly LLMConfigPreferences $preferences;

    public function __construct(
        private readonly LoggerInterface    $logger,
        private readonly LLMConfigService $llmConfigService,
        private readonly int               $llmTimeout = 300,
        ?LLMConfigPreferences $preferences = null,
    ) {
        $this->preferences = $preferences ?? new LLMConfigPreferences();
    }

    public function makeFromAgent(Agent $agent): LLMDriverInterface
    {
        $config = $this->preferences->getEffectiveConfigForAgent($agent);

        if ($config !== null) {
            return $this->makeDriverFromConfig($config);
        }

        throw new LlmConfigurationMissingException(
            'No LLM configuration found for this agent. Set a preferred config or ensure a global default exists.',
        );
    }

    public function makeDriverFromConfig(LLMDriverConfiguration $config): LLMDriverInterface
    {
        $driverClass = $config->driver_class;

        if (! class_exists($driverClass)) {
            throw new DriverClassNotFoundException("LLM driver class {$driverClass} does not exist.");
        }

        try {
            $settings = $this->llmConfigService->decodeSettings($config->driver_class, $config->settings ?? '');
        } catch (Throwable $e) {
            throw new LLMConfigDecryptFailedException(
                "Failed to decrypt settings for LLM config '{$config->name}' (id={$config->id}): " . $e->getMessage(),
                0,
                $e,
            );
        }

        // Per-LLM-config timeout override; falls back to the global default.
        $timeout = isset($settings['timeout']) && $settings['timeout'] !== ''
            ? (int) $settings['timeout']
            : $this->llmTimeout;

        $supportsImageInput = $this->decodeSupportsImageInput($settings);

        $commonArgs = [
            'apiKey' => (string) ($settings['api_key'] ?? ''),
            'model' => (string) ($settings['model'] ?? ''),
            'baseUrl' => (string) ($settings['base_url'] ?? ''),
            'httpClient' => \Symfony\Component\HttpClient\HttpClient::create(),
            'logger' => $this->logger,
            'timeout' => $timeout,
            'supportsImageInput' => $supportsImageInput,
        ];

        if ($driverClass === AnthropicCompatibleDriver::class) {
            $options = new AnthropicDriverOptions(
                thinkingBudget: isset($settings['thinking_budget']) && $settings['thinking_budget'] !== ''
                    ? (int) $settings['thinking_budget']
                    : null,
                supportsImageInput: $supportsImageInput,
                enablePromptCaching: $this->decodeEnablePromptCaching($settings),
            );
            // Anthropic folds supportsImageInput into AnthropicDriverOptions
            // so its constructor stays at 7 params (S107 cap).
            $argsForDriver = $commonArgs;
            unset($argsForDriver['supportsImageInput']);
            return new AnthropicCompatibleDriver(...$argsForDriver, options: $options);
        }

        return new $driverClass(...$commonArgs);
    }

    /**
     * Decode the operator-controlled `supports_image_input` setting. The
     * frontend form serialises booleans as the strings `"true"`/`"false"`,
     * so we cast defensively. A missing key preserves the legacy null
     * fallback (driver heuristic).
     *
     * @param array<string, mixed> $settings
     */
    private function decodeSupportsImageInput(array $settings): ?bool
    {
        if (!array_key_exists('supports_image_input', $settings)) {
            return null;
        }
        return filter_var($settings['supports_image_input'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Decode the Anthropic-only `enable_prompt_caching` toggle. Defaults
     * to `true` when the key is absent so legacy configs keep their
     * previous behaviour. Only consumed by `AnthropicDriverOptions`.
     *
     * @param array<string, mixed> $settings
     */
    private function decodeEnablePromptCaching(array $settings): bool
    {
        if (!array_key_exists('enable_prompt_caching', $settings)) {
            return true;
        }
        return filter_var($settings['enable_prompt_caching'], FILTER_VALIDATE_BOOLEAN);
    }
}
