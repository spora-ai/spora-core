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
     * @return list<array{key: string, label: string, type: string, description: string, default: mixed, required: bool, options: array|null}>
     */
    public function buildSchemaFromClass(string $class): array
    {
        $schema = [];
        foreach (ToolSettingSchema::collect($class) as $setting) {
            // `label` and `options[].label` stay English; the config block
            // that reaches the model carries them, and a translated label
            // would apply to every setting reusing the same word. See
            // {@see \Spora\Plugins\PluginInterface::translationsPaths()}.
            $schema[] = [
                'key' => $setting->key,
                'label' => $setting->label,
                'type' => $setting->type,
                'description' => $this->translator->trans($setting->description),
                'default' => $setting->default,
                'required' => $setting->required,
                'options' => $setting->options,
            ];
        }

        return $schema;
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
