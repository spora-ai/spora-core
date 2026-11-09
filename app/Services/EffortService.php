<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Drivers\DriverFactory;
use Spora\Drivers\EffortAwareDriverInterface;
use Spora\Drivers\Exceptions\DriverClassNotFoundException;
use Spora\Drivers\Exceptions\LLMConfigDecryptFailedException;
use Spora\Drivers\LLMDriverInterface;
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
        $driver = $this->driverFor($config);

        if (!$driver instanceof EffortAwareDriverInterface) {
            return ['supported' => false, 'levels' => [], 'default' => $default];
        }

        $levels = $driver->effortLevels();

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
