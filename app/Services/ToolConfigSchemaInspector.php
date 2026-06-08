<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Models\Agent;
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
    public function __construct()
    {
        // Intentionally empty: this collaborator is reflection-driven and
        // stateless. The original ToolConfigService took the tool_classes
        // array and SecurityManager as constructor args so it could build
        // the inspector + the cryptography + the name resolver on demand.
        // After the split, the inspector no longer needs the password-key
        // resolver or the schema — those live on ToolConfigCryptographer
        // (for encryption) and the per-call schema queries (in this class).
        // Each public method resolves the schema it needs via ReflectionClass.
    }

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
            if ($setting->type === 'multi-select') {
                // Stored as int[] — empty array unless the schema overrides.
                $defaults[$setting->key] = $setting->default ?? [];
                continue;
            }
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
     * Return keys of all #[ToolSetting] attributes where type === 'multi-select'.
     *
     * @return list<string>
     */
    public function getMultiSelectKeys(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $keys = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            if ($setting->type === 'multi-select') {
                $keys[] = $setting->key;
            }
        }

        return $keys;
    }

    /**
     * Annotate effective settings with the human-readable label for each
     * `exposeToLlm === true` field. The facade supplies the effective
     * settings (computed from the cascade); this method only filters
     * down to the LLM-visible subset and attaches the label.
     *
     * `multi-select` fields stored as `int[]` are resolved to `list<string>` of
     * the form `["Name (#id)"]` so the LLM can see the human-readable agent
     * names alongside the IDs it must reference.
     *
     * @param  array<string, mixed> $effectiveSettings
     * @return array<string, array{label: string, value: mixed}>
     */
    public function getLlmToolSettings(string $toolClass, array $effectiveSettings): array
    {
        $labels        = $this->getLlmSettingLabels($toolClass);
        $multiKeys     = array_flip($this->getMultiSelectKeys($toolClass));
        $resolvedNames = $this->resolveAgentNames($toolClass, $effectiveSettings, $multiKeys);

        $result = [];
        foreach ($labels as $key => $label) {
            $value = $effectiveSettings[$key] ?? null;
            if (isset($multiKeys[$key])) {
                $value = $this->formatAgentIdList($value, $resolvedNames);
            }
            $result[$key] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed> $effectiveSettings
     * @param  array<string, int>   $multiKeys        keys of multi-select settings (flipped for isset())
     * @return array<int, string>                     id => "Name"
     */
    private function resolveAgentNames(string $toolClass, array $effectiveSettings, array $multiKeys): array
    {
        if ($multiKeys === []) {
            return [];
        }

        $ids = [];
        foreach ($multiKeys as $key => $_) {
            $value = $effectiveSettings[$key] ?? null;
            if (is_array($value)) {
                foreach ($value as $id) {
                    $intId = (int) $id;
                    if ($intId > 0) {
                        $ids[$intId] = $intId;
                    }
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $names = Agent::whereIn('id', array_values($ids))->get(['id', 'name']);

        return $names->mapWithKeys(static fn(Agent $a) => [(int) $a->id => (string) $a->name])->all();
    }

    /**
     * @param  mixed              $value
     * @param  array<int, string> $names  id => name
     * @return list<string>
     */
    private function formatAgentIdList(mixed $value, array $names): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $id) {
            $intId = (int) $id;
            if ($intId <= 0) {
                continue;
            }
            $out[] = isset($names[$intId])
                ? "{$names[$intId]} (#{$intId})"
                : "#{$intId}";
        }
        return $out;
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
