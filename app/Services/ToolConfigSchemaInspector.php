<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Skills\Skill;
use Spora\Tools\ToolSettingSchema;

/**
 * Reads the `#[ToolSetting]` attribute schema declared on tool classes.
 *
 * The inspector answers schema questions that do not require the DB:
 * defaults, required-key reports, password masking for the API, and the
 * subset of settings exposed to the LLM. All methods are reflection
 * driven and read-only.
 *
 * The `multi-select` resolver branches on the setting's `resolveAs`
 * attribute:
 * - 'agent' (default) — stored as `int[]`; LLM-facing values are
 *   resolved against the Agent model to "Name (#id)" strings.
 * - 'skill'           — stored as `string[]` of slugs; LLM-facing
 *   values are resolved against the bundled `$skillsByName` map to
 *   "name: short description" strings (description truncated to ~80
 *   chars).
 * - 'raw'             — stored and surfaced as-is. Use when neither
 *   agent nor skill resolution fits the field's semantics.
 */
final class ToolConfigSchemaInspector
{
    /**
     * Skill name → Skill map, populated by the container from the
     * SkillScanner. Used to resolve `resolveAs: 'skill'` multi-select
     * settings at LLM-exposure time.
     *
     * @var array<string, Skill>
     */
    private readonly array $skillsByName;

    /**
     * @param array<string, Skill> $skillsByName
     */
    public function __construct(array $skillsByName = [])
    {
        $this->skillsByName = $skillsByName;
    }

    /**
     * Return keys of all #[ToolSetting] attributes where type === 'password'.
     *
     * @return list<string>
     */
    public function getPasswordKeys(string $toolClass): array
    {
        $keys = [];
        foreach (ToolSettingSchema::collect($toolClass) as $instance) {
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
        $defaults = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
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
        $missing = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            if (!$setting->required) {
                continue;
            }

            $value = $effectiveSettings[$setting->key] ?? null;
            // multi-select defaults to [] in getSchemaDefaults — without this
            // arm a required allowlist with zero entries would silently pass
            // the "configured" gate and let the tool be enabled empty.
            $isEmpty = $value === null
                || $value === ''
                || (is_array($value) && $value === []);
            if ($isEmpty) {
                $missing[] = $setting->key;
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
        $keys = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            if ($setting->type === 'multi-select') {
                $keys[] = $setting->key;
            }
        }
        return $keys;
    }

    /**
     * Coerce stored multi-select values to the array type declared by the
     * setting's `resolveAs` field. Three branches:
     *
     * - 'agent' (default) — decode JSON to `int[]` (Agent id list).
     * - 'skill'           — decode JSON to `string[]` of validated skill
     *                       slugs (`^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$`).
     * - 'raw'             — decode JSON to `array` of mixed values, no
     *                       element-wise coercion.
     *
     * Multi-select settings travel through the form layer as JSON-encoded
     * strings (the form is `Record<string, string>`), so the cryptographer
     * round-trips them as literal strings. This method decodes them back
     * to their array-typed form. Non-multi-select keys are left untouched.
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function normalizeMultiSelectValues(string $toolClass, array $settings): array
    {
        $resolveAsByKey = $this->getResolveAsByKey($toolClass);

        foreach ($resolveAsByKey as $key => $resolveAs) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = $settings[$key];
            $settings[$key] = match ($resolveAs) {
                'skill' => $this->normalizeSkillList($value),
                'raw'   => $this->normalizeRawList($value),
                default => $this->normalizeAgentIdList($value),
            };
        }

        return $settings;
    }

    /**
     * Annotate effective settings with the human-readable label for each
     * `exposeToLlm === true` field. The facade supplies the effective
     * settings (computed from the cascade); this method only filters
     * down to the LLM-visible subset and attaches the label.
     *
     * `multi-select` values are resolved per the setting's `resolveAs`:
     * - 'agent' → `list<string>` of `"Name (#id)"` (existing behaviour).
     * - 'skill' → `list<string>` of `"name: short description"` (truncated).
     * - 'raw'   → value is surfaced unchanged.
     *
     * @param  array<string, mixed> $effectiveSettings
     * @return array<string, array{label: string, value: mixed}>
     */
    public function getLlmToolSettings(string $toolClass, array $effectiveSettings, ?int $userId = null): array
    {
        $labels        = $this->getLlmSettingLabels($toolClass);
        $multiKeys     = array_flip($this->getMultiSelectKeys($toolClass));
        $resolveAsByKey = $this->getResolveAsByKey($toolClass);
        $resolvedAgentNames = $this->resolveAgentNames($effectiveSettings, $multiKeys, $resolveAsByKey, $userId);

        $result = [];
        foreach ($labels as $key => $label) {
            $value = $effectiveSettings[$key] ?? null;
            if (isset($multiKeys[$key])) {
                $resolveAs = $resolveAsByKey[$key] ?? 'agent';
                $value = match ($resolveAs) {
                    'skill' => $this->formatSkillList($value),
                    'raw'   => is_array($value) ? array_values($value) : [],
                    default => $this->formatAgentIdList($value, $resolvedAgentNames),
                };
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
     * @param  array<string, string> $resolveAsByKey  key => resolveAs ('agent' | 'skill' | 'raw')
     * @param  int|null             $userId           scope of the lookup; null returns no names
     * @return array<int, string>                     id => "Name"
     */
    private function resolveAgentNames(array $effectiveSettings, array $multiKeys, array $resolveAsByKey, ?int $userId): array
    {
        // Multi-select values are user-controlled, so an unscoped lookup would
        // happily resolve another tenant's agent name and leak it to the LLM
        // (and downstream into the tool-call render). Without a user we can
        // never prove ownership — fall back to "#id" by returning no names.
        if ($multiKeys === [] || $userId === null) {
            return [];
        }

        $ids = [];
        foreach ($multiKeys as $key => $_) {
            // Only resolve agent-typed multi-selects; other resolveAs branches
            // (skill, raw) don't have integer IDs.
            if (($resolveAsByKey[$key] ?? 'agent') !== 'agent') {
                continue;
            }
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

        $names = Agent::where('user_id', $userId)
            ->whereIn('id', array_values($ids))
            ->get(['id', 'name']);

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
     * Resolve a list of skill slugs to a list of "name: short description"
     * strings for LLM exposure. Description is truncated to ~80 chars
     * with an ellipsis; the full body is available on demand via
     * `skill_read`. Slugs that no longer exist on disk (renamed or
     * removed after selection) are silently skipped.
     *
     * @param  mixed $value
     * @return list<string>
     */
    private function formatSkillList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $slug) {
            $slug = (string) $slug;
            if ($slug === '' || !isset($this->skillsByName[$slug])) {
                continue;
            }
            $skill = $this->skillsByName[$slug];
            $description = $skill->description();
            $truncated = mb_strlen($description) > 80
                ? mb_substr($description, 0, 77) . '...'
                : $description;
            $out[] = $truncated === ''
                ? $skill->name()
                : "{$skill->name()}: {$truncated}";
        }
        return $out;
    }

    /**
     * Coerce a value to `string[]` of validated skill slugs.
     * Accepts arrays directly or JSON-encoded strings; rejects entries
     * that don't match the skill name pattern.
     *
     * @param  mixed $value
     * @return list<string>
     */
    private function normalizeSkillList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($value as $entry) {
            $slug = strtolower(trim((string) $entry));
            // Negative lookahead rejects consecutive hyphens per the
            // agentskills.io name pattern; mirrors {@see SkillValidator}.
            if ($slug === '' || !preg_match('/^(?![a-z0-9-]*--)[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/', $slug)) {
                continue;
            }
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
        }
        return $out;
    }

    /**
     * Coerce a value to a raw `array` — JSON-decoded if string, passed
     * through if already array, `[]` otherwise. Element-wise type is
     * preserved (caller's responsibility).
     *
     * @param  mixed $value
     * @return list<mixed>
     */
    private function normalizeRawList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values($value);
    }

    /**
     * Coerce a value to `int[]` — the historical multi-select format
     * used by HandoverTool's `allowed_target_agents`.
     *
     * @param  mixed $value
     * @return list<int>
     */
    private function normalizeAgentIdList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('intval', $value));
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded)
                ? array_values(array_map('intval', $decoded))
                : [];
        }
        return [];
    }

    /**
     * @return array<string, string>  key => resolveAs
     */
    private function getResolveAsByKey(string $toolClass): array
    {
        $map = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            if ($setting->type !== 'multi-select') {
                continue;
            }
            $map[$setting->key] = $setting->resolveAs;
        }
        return $map;
    }

    /**
     * Return key => label map for all #[ToolSetting] fields where exposeToLlm === true.
     *
     * @return array<string, string>
     */
    private function getLlmSettingLabels(string $toolClass): array
    {
        $labels = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            if ($setting->exposeToLlm) {
                $labels[$setting->key] = $setting->label;
            }
        }
        return $labels;
    }
}
