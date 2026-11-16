<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Drivers\LLMDriverConfigInterface;
use Spora\I18n\CatalogLoader;
use Spora\I18n\Translator;
use Spora\Tools\ToolSettingSchema;

/**
 * Driver discovery and settings schema introspection for LLM config.
 *
 * Resolves the list of registered driver classes, their display names,
 * the settings schema derived from #[ToolSetting] attributes, and the
 * keys whose value is a secret (type "password"). Pure, no DB access,
 * so it can be unit-tested without booting Eloquent.
 */
final class LLMConfigSchemaInspector
{
    private readonly Translator $translator;

    /**
     * @param list<class-string<LLMDriverConfigInterface>> $driverClasses
     */
    public function __construct(
        private readonly array $driverClasses = [],
        ?Translator $translator = null,
    ) {
        $this->translator = $translator ?? new Translator(new CatalogLoader());
    }

    /**
     * Returns all registered driver classes with their resolved schemas.
     *
     * @return list<array{name: string, display_name: string, driver_class: string, settings_schema: list<array>}>
     */
    public function getDrivers(): array
    {
        $drivers = [];

        foreach ($this->driverClasses as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $drivers[] = [
                'name' => $class::getName(),
                'display_name' => $this->translator->trans($class::getDisplayName()),
                'driver_class' => $class,
                'settings_schema' => $this->buildSchemaFromClass($class),
            ];
        }

        return $drivers;
    }

    /**
     * @return list<array{key: string, label: string, type: string, description: string, default: mixed, required: bool, options: array|null, default_applies_when_absent: bool}>
     */
    public function buildSchemaFromClass(string $class): array
    {
        $schema = [];
        foreach (ToolSettingSchema::collect($class) as $setting) {
            // `label` and `options[].label` are never translated — see
            // {@see \Spora\Build\CatalogHarvester} — and the config block
            // that reaches the model carries them.
            $schema[] = [
                'key' => $setting->key,
                'label' => $setting->label,
                'type' => $setting->type,
                'description' => $this->translator->trans($setting->description),
                'default' => $setting->default,
                'required' => $setting->required,
                'options' => $setting->options,
                'default_applies_when_absent' => $setting->defaultAppliesWhenAbsent,
            ];
        }

        return $schema;
    }

    /**
     * The defaults a configuration takes for the settings it does not carry.
     *
     * The settings form renders the stored settings, so a setting a
     * configuration never saved shows as unset — and for a toggle that
     * reads as "off", which is the opposite of what runs. A setting whose
     * absent value is its default says so in its schema entry, and the
     * default is filled in here.
     *
     * Only the settings that declare it are filled in. `supports_image_input`
     * does not: an absent key there means the driver decides from the model,
     * so its default is not what the key's absence means.
     *
     * @param array<string, mixed> $settings
     * @param list<array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    public function applyAbsentDefaults(array $settings, array $schema): array
    {
        foreach ($schema as $field) {
            $key = $field['key'] ?? null;

            if (!is_string($key) || ($field['default_applies_when_absent'] ?? false) !== true) {
                continue;
            }

            if (array_key_exists($key, $settings) || !array_key_exists('default', $field)) {
                continue;
            }

            $default = $field['default'];

            if ($default === null) {
                continue;
            }

            if (is_bool($default)) {
                $settings[$key] = $default ? 'true' : 'false';
                continue;
            }

            $settings[$key] = (string) $default;
        }

        return $settings;
    }

    /**
     * @return list<array>
     */
    public function getSchemaForDriver(string $driverClass): array
    {
        return $this->buildSchemaFromClass($driverClass);
    }

    public function getDriverName(string $driverClass): string
    {
        if (! class_exists($driverClass)) {
            return $driverClass;
        }
        return $driverClass::getName();
    }

    public function getDriverDisplayName(string $driverClass): string
    {
        if (! class_exists($driverClass)) {
            return $driverClass;
        }
        return $driverClass::getDisplayName();
    }

    /**
     * @return list<string>
     */
    private function getPasswordKeys(string $driverClass): array
    {
        $keys = [];
        foreach (ToolSettingSchema::collect($driverClass) as $instance) {
            if ($instance->type === 'password') {
                $keys[] = $instance->key;
            }
        }
        return $keys;
    }

    /**
     * @return list<string>
     */
    public function getPasswordKeysFor(string $driverClass): array
    {
        return $this->getPasswordKeys($driverClass);
    }
}
