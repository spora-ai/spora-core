<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Drivers\LLMDriverConfigInterface;
use Spora\Tools\Attributes\ToolSetting;

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
    /**
     * @param list<class-string<LLMDriverConfigInterface>> $driverClasses
     */
    public function __construct(
        private readonly array $driverClasses = [],
    ) {}

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
                'display_name' => $class::getDisplayName(),
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
        if (! class_exists($class)) {
            return [];
        }

        $ref = new ReflectionClass($class);

        $schema = [];
        foreach ($ref->getAttributes(ToolSetting::class) as $attr) {
            $setting = $attr->newInstance();
            $schema[] = [
                'key' => $setting->key,
                'label' => $setting->label,
                'type' => $setting->type,
                'description' => $setting->description,
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
        if (! class_exists($driverClass)) {
            return [];
        }

        $reflection = new ReflectionClass($driverClass);
        $keys = [];

        foreach ($reflection->getAttributes(ToolSetting::class) as $attribute) {
            /** @var ToolSetting $instance */
            $instance = $attribute->newInstance();
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
