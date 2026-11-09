<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Drivers\DriverFactory;
use Spora\Drivers\EffortAwareDriverInterface;
use Spora\Drivers\Exceptions\DriverClassNotFoundException;
use Spora\Drivers\Exceptions\LLMConfigDecryptFailedException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Tools\ToolSettingSchema;
use Throwable;

/**
 * The reasoning-effort capabilities of an agent's effective driver.
 *
 * The effective configuration is resolved here, on the server, through the
 * same tier resolution the tick uses. The frontend must never re-implement
 * it: a picker that offers levels the agent's driver cannot express sends
 * a request the provider answers with a 400.
 */
final class EffortService
{
    public function __construct(
        private readonly LLMConfigPreferences $preferences,
        private readonly LLMConfigService $llmConfigService,
        private readonly DriverFactory $driverFactory,
    ) {}

    /**
     * What the agent's driver can express, and what it defaults to.
     *
     * @return array{supported: bool, levels: list<array{value: string, label: string}>, default: ?string}
     */
    public function describe(Agent $agent): array
    {
        $config = $this->preferences->getEffectiveConfigForAgent($agent);

        if ($config === null) {
            return ['supported' => false, 'levels' => [], 'default' => null];
        }

        $default = $this->defaultEffort($config);
        $levels = $this->supportedLevels($config);

        if ($levels === []) {
            return ['supported' => false, 'levels' => [], 'default' => $default];
        }

        return [
            'supported' => true,
            'levels' => $this->labelled($config->driver_class, $levels),
            'default' => $default,
        ];
    }

    /**
     * The levels the configuration's driver can express, or none.
     *
     * A driver that does not speak effort, and one that speaks it without
     * naming a level, both leave the agent without a picker.
     *
     * @return array<string, string>
     */
    private function supportedLevels(LLMDriverConfiguration $config): array
    {
        $driver = $this->driverFor($config);

        if (!$driver instanceof EffortAwareDriverInterface) {
            return [];
        }

        return $driver->effortLevels();
    }

    /**
     * The canonical levels the agent's effective driver can express.
     *
     * The same resolution {@see self::describe()} uses, so the levels a
     * request is refused against are the levels the client was told about.
     *
     * @return list<string>
     */
    public function declaredLevels(Agent $agent): array
    {
        $config = $this->preferences->getEffectiveConfigForAgent($agent);

        if ($config === null) {
            return [];
        }

        return array_keys($this->supportedLevels($config));
    }

    /**
     * Refuse an effort the agent's driver does not offer.
     *
     * A level the driver cannot express would be dropped on the way to the
     * provider, and the request would run at the model's own default
     * instead of the level the caller asked for. The API tells the client
     * which levels the driver offers, so a level outside that set is
     * refused here rather than silently downgraded.
     *
     * A level the driver *does* offer is not checked any further: the
     * provider validates the level against the model it is talking to.
     *
     * A null agent is not an answer: the caller could not resolve the
     * agent the request is for, and the request is refused for that
     * reason further up.
     *
     * @throws EffortValidationException
     */
    public function assertLevel(?string $level, ?Agent $agent): void
    {
        if ($level === null || $level === '' || $agent === null) {
            return;
        }

        $declared = $this->declaredLevels($agent);

        if (in_array($level, $declared, true)) {
            return;
        }

        if ($declared === []) {
            throw new EffortValidationException(
                sprintf('effort "%s" is not offered by this agent\'s LLM driver: it cannot express a reasoning effort.', $level),
                EffortValidationException::CODE_UNSUPPORTED,
            );
        }

        throw new EffortValidationException(
            sprintf('effort "%s" is not offered by this agent\'s LLM driver. Supported levels: %s.', $level, implode(', ', $declared)),
            EffortValidationException::CODE_UNSUPPORTED,
        );
    }

    /**
     * The configured default, or null for the model default.
     */
    private function defaultEffort(LLMDriverConfiguration $config): ?string
    {
        try {
            $settings = $this->llmConfigService->decryptSettings($config->driver_class, $config->settings ?? '');
        } catch (Throwable) {
            return null;
        }

        $effort = $settings['effort'] ?? null;

        return is_string($effort) && $effort !== '' ? $effort : null;
    }

    /**
     * The driver of a configuration, or null when it cannot be built.
     *
     * A configuration that names a driver class nobody ships, or whose
     * settings cannot be decrypted, cannot express an effort — the agent
     * cannot run with it either.
     */
    private function driverFor(LLMDriverConfiguration $config): ?LLMDriverInterface
    {
        try {
            return $this->driverFactory->makeDriverFromConfig($config);
        } catch (DriverClassNotFoundException | LLMConfigDecryptFailedException) {
            return null;
        }
    }

    /**
     * The levels with the driver's own labels.
     *
     * The label map is the `effort` setting's `options`; a level the map
     * does not name falls back to its canonical value, so a driver that
     * adds a level before it adds a label still shows something readable.
     *
     * @param  array<string, string> $levels canonical level => provider wire value
     * @return list<array{value: string, label: string}>
     */
    private function labelled(string $driverClass, array $levels): array
    {
        $options = $this->effortOptions($driverClass);

        $labelled = [];
        foreach (array_keys($levels) as $level) {
            $labelled[] = [
                'value' => $level,
                'label' => $options[$level] ?? $level,
            ];
        }

        return $labelled;
    }

    /**
     * @return array<array-key, string>
     */
    private function effortOptions(string $driverClass): array
    {
        foreach (ToolSettingSchema::collect($driverClass) as $setting) {
            if ($setting->key === 'effort') {
                return $setting->options;
            }
        }

        return [];
    }
}
