<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Tools\Attributes\ToolSetting;

/**
 * Reads the `#[ToolSetting]` attribute schema declared on tool classes.
 *
 * The inspector answers schema questions that do not require the DB:
 * defaults, required-key reports, password masking for the API, and the
 * subset of settings exposed to the LLM. All methods are reflection
 * driven and read-only.
 */
final class ToolConfigSchemaInspector
{
    public function __construct() {}

    /**
     * Return keys of all #[ToolSetting] attributes where type === 'password'.
     *
     * @return list<string>
     */
    public function getPasswordKeys(string $toolClass): array
    {
        $reflection = new ReflectionClass($toolClass);
        $keys       = [];

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
     * Return schema defaults as key => default_value for all #[ToolSetting] fields.
     * Used to pre-seed agent overrides when enabling a tool.
     *
     * @return array<string, mixed>
     */
    public function getSchemaDefaults(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $defaults = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            if ($setting->default !== null) {
                $defaults[$setting->key] = $setting->default;
            }
        }

        return $defaults;
    }

    /**
     * Return keys of required settings that have no value (null or empty) in the given effective settings.
     *
     * @param  array<string, mixed> $effectiveSettings
     * @return list<string>
     */
    public function getMissingRequiredSettings(string $toolClass, array $effectiveSettings): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $missing = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            if ($setting->required) {
                $value = $effectiveSettings[$setting->key] ?? null;
                if ($value === null || $value === '') {
                    $missing[] = $setting->key;
                }
            }
        }

        return $missing;
    }

    /**
     * Return a copy of settings with password fields replaced by "***".
     * Null/empty password fields are left as-is.
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function maskForApi(array $settings, string $toolClass): array
    {
        $passwordKeys = $this->getPasswordKeys($toolClass);
        $masked       = $settings;

        foreach ($passwordKeys as $key) {
            if (array_key_exists($key, $masked) && $masked[$key] !== null && $masked[$key] !== '') {
                $masked[$key] = '***';
            }
        }

        return $masked;
    }

    /**
     * Annotate effective settings with the human-readable label for each
     * `exposeToLlm === true` field. The facade supplies the effective
     * settings (computed from the cascade); this method only filters
     * down to the LLM-visible subset and attaches the label.
     *
     * @param  array<string, mixed> $effectiveSettings
     * @return array<string, array{label: string, value: mixed}>
     */
    public function getLlmToolSettings(string $toolClass, array $effectiveSettings): array
    {
        $labels = $this->getLlmSettingLabels($toolClass);

        $result = [];
        foreach ($labels as $key => $label) {
            $result[$key] = [
                'label' => $label,
                'value' => $effectiveSettings[$key] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Return key => label map for all #[ToolSetting] fields where exposeToLlm === true.
     *
     * @return array<string, string>
     */
    private function getLlmSettingLabels(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $labels = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            if ($setting->exposeToLlm) {
                $labels[$setting->key] = $setting->label;
            }
        }

        return $labels;
    }
}
