<?php

declare(strict_types=1);

namespace Spora\Drivers;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
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
 * Step 3 is a hard stop. The previous "fall back to an empty-key
 * OpenAI driver" silently punted the request to api.openai.com with
 * `apiKey: ''` and surfaced as an upstream 401, hiding the operator-
 * visible "no LLM configured" error. ErrorClassifier's
 * markTaskNoLlmConfiguration() writes the friendly
 * NO_LLM_CONFIGURATION message to the task row.
 *
 * The `supports_image_input` and `supports_effort` toggles are read from
 * the settings blob. The frontend round-trips booleans as the strings
 * `"true"`/`"false"`, so a value of `"false"` (or any falsy string) decodes
 * to `false`, and the absence of the key decodes to `null` so the driver
 * falls back to its legacy behaviour for rows persisted before the toggle
 * shipped.
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

    /**
     * The driver a configuration names, from its stored settings.
     */
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

        return $this->makeDriverFromSettings($driverClass, $settings);
    }

    /**
     * The driver a driver class and a set of settings describe.
     *
     * The settings are the plaintext ones a save request carries — the
     * stored blob is decrypted by {@see self::makeDriverFromConfig()}
     * before it gets here, so the validator and the runtime read the same
     * values and cannot disagree about which levels a configuration
     * offers.
     *
     * The class that is built is the registry's own, never the name the
     * request carried: a save request names a driver, and a name nobody
     * registered is not one. The registry is the set of drivers this
     * installation ships, and a class outside it is not a driver however
     * it is spelled.
     *
     * @param array<string, mixed> $settings
     * @throws DriverClassNotFoundException when the driver class is not registered
     * @throws InvalidArgumentException when `effort_levels` is malformed
     */
    public function makeDriverFromSettings(string $driverClass, array $settings): LLMDriverInterface
    {
        $registered = $this->registeredDriverClass($driverClass);

        if ($registered === null) {
            throw new DriverClassNotFoundException("LLM driver class {$driverClass} is not a registered driver.");
        }

        $commonArgs = [
            'apiKey' => (string) ($settings['api_key'] ?? ''),
            'model' => (string) ($settings['model'] ?? ''),
            'baseUrl' => (string) ($settings['base_url'] ?? ''),
            'httpClient' => \Symfony\Component\HttpClient\HttpClient::create(),
            'logger' => $this->logger,
            'timeout' => $this->timeoutFor($settings),
            'supportsImageInput' => $this->decodeToggle($settings, 'supports_image_input'),
            'effortLevels' => $this->decodeEffortLevels($settings),
            'supportsEffort' => $this->decodeToggle($settings, 'supports_effort'),
        ];

        if (is_a($registered, DriverOptionsAwareInterface::class, true)) {
            return $registered::makeFromSettings($commonArgs, $settings);
        }

        return new $registered(...$this->acceptedArguments($registered, $commonArgs));
    }

    /**
     * The registry's own class for a driver name, or null when the name is
     * not one of the registered drivers.
     *
     * The returned string is the registry's, so what reaches the
     * instantiation is a class this installation ships rather than a name
     * a request carried.
     */
    private function registeredDriverClass(string $driverClass): ?string
    {
        foreach ($this->llmConfigService->driverClasses() as $registered) {
            if ($registered === $driverClass) {
                return $registered;
            }
        }

        return null;
    }

    /**
     * The arguments a driver's constructor actually declares.
     *
     * A driver that predates the effort parameters keeps working: it does
     * not receive them, so its own level set applies. A named-argument
     * spread of the whole set would fail on the first unknown key instead.
     *
     * @param array<string, mixed> $commonArgs
     * @return array<string, mixed>
     */
    private function acceptedArguments(string $driverClass, array $commonArgs): array
    {
        $declared = [];

        foreach ((new ReflectionMethod($driverClass, '__construct'))->getParameters() as $parameter) {
            $declared[$parameter->getName()] = true;
        }

        return array_intersect_key($commonArgs, $declared);
    }

    /**
     * The per-configuration timeout, falling back to the global default.
     *
     * @param array<string, mixed> $settings
     */
    private function timeoutFor(array $settings): int
    {
        return isset($settings['timeout']) && $settings['timeout'] !== ''
            ? (int) $settings['timeout']
            : $this->llmTimeout;
    }

    /**
     * The reasoning-effort levels the configuration names.
     *
     * @param array<string, mixed> $settings
     * @return list<string>
     * @throws InvalidArgumentException when the value is malformed
     */
    private function decodeEffortLevels(array $settings): array
    {
        $raw = $settings['effort_levels'] ?? '';

        return EffortLevelSet::fromSetting(is_string($raw) ? $raw : '')->names();
    }

    /**
     * Decode an operator-controlled toggle. The frontend form serialises
     * booleans as the strings `"true"`/`"false"`, so we cast defensively.
     * A missing key preserves the legacy null fallback.
     *
     * @param array<string, mixed> $settings
     */
    private function decodeToggle(array $settings, string $key): ?bool
    {
        if (!array_key_exists($key, $settings)) {
            return null;
        }
        return filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
