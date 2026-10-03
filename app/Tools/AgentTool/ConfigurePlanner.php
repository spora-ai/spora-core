<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ToolSettingSchema;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Per-operation validation + apply for `configure_tools`.
 *
 * Flow:
 *   1. `buildPlan` walks each `tools[i]` entry once
 *   2. `parseEntry` validates the entry's shape
 *   3. `parseOperations` validates the entry's operations
 *      (defensively `unwrapSingleItemArray`-ing the OpenAI
 *      `{item: [...]}` quirk)
 *   4. `parseSettings` validates the entry's settings against the
 *      `#[ToolSetting]` declarations of that tool class
 *   5. `apply` writes each plan step through
 *      `AgentToolSettingsServiceInterface` so the LLM-facing path
 *      and the operator-facing API share the same enable / override
 *      semantics.
 *
 * A `settings` write is stored in the form the settings form uses: a
 * `Record<string, string>`, so a multi-select travels as a JSON-encoded
 * string. `ToolConfigService::getEffectiveSettings()` normalises it back to
 * an array on read, so the two forms meet there rather than in every reader.
 * The panel's own reader is the reason for the JSON: it fetches
 * `?raw=true` and `JSON.parse`s, so a nested array would read back as
 * "nothing configured".
 *
 * The principal check rides here, on the write, because it is a property of
 * the write: a skill name is a claim about what the executing principal can
 * see, and a write that cannot make that claim should not be able to grant
 * the name either.
 */
final class ConfigurePlanner
{
    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';

    public function __construct(
        private readonly AgentToolSettingsServiceInterface $toolSettings,
        private readonly ?SkillProviderRegistry $skills = null,
    ) {}

    /**
     * @param  mixed $entries
     * @param  int|null $principalId The principal whose visible skills an
     *        `allowed_skills` write may name. Null resolves no principal, so a
     *        provider that scopes by one sees nothing and every name is refused.
     * @return list<array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}>|ToolResult
     */
    public function buildPlan(mixed $entries, ?int $principalId = null): array|ToolResult
    {
        $plan = [];
        foreach ($entries as $i => $entry) {
            $step = $this->parseEntry($entry, $i, $principalId);
            if ($step instanceof ToolResult) {
                return $step;
            }
            $plan[] = $step;
        }
        return $plan;
    }

    /**
     * Apply the validated `configure_tools` plan.
     *
     * @param  list<array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}> $plan
     */
    public function apply(int $agentId, int $userId, array $plan): void
    {
        foreach ($plan as $step) {
            if ($step['enable']) {
                $this->toolSettings->enableTool($agentId, $userId, $step['tool_class']);
            } else {
                $this->toolSettings->disableTool($agentId, $userId, $step['tool_class']);
            }
            foreach ($step['operations'] as $op) {
                $this->toolSettings->patchOperationOverride(
                    $agentId,
                    $userId,
                    $step['tool_class'],
                    $op['name'],
                    [
                        'enabled'                   => $op['enabled'] ? 1 : 0,
                        'default_requires_approval' => $op['auto_approve'] ? 0 : 1,
                    ],
                );
            }
            if ($step['settings'] !== []) {
                $this->toolSettings->putOverride($agentId, $userId, $step['tool_class'], $step['settings']);
            }
        }
    }

    /**
     * @param  mixed $entry
     * @return array{tool_class: string, enable: bool, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}|ToolResult
     */
    private function parseEntry(mixed $entry, int $i, ?int $principalId): array|ToolResult
    {
        $shapeFail = $this->shapeEntryFailure($entry, $i);
        if ($shapeFail !== null) {
            return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . $shapeFail);
        }
        $toolClass = (string) ($entry['tool_class'] ?? '');

        $operations = $this->parseOperations($entry['operations'] ?? [], $i);
        if ($operations instanceof ToolResult) {
            return $operations;
        }
        $settings = $this->parseSettings($entry['settings'] ?? [], $toolClass, $i, $principalId);
        if ($settings instanceof ToolResult) {
            return $settings;
        }
        return [
            'tool_class' => $toolClass,
            'enable'     => (bool) ($entry['enabled'] ?? true),
            'operations' => $operations,
            'settings'   => $settings,
        ];
    }

    private function shapeEntryFailure(mixed $entry, int $i): ?string
    {
        if (!is_array($entry)) {
            return "tool entry #{$i} must be an object.";
        }
        if (!isset($entry['tool_class']) || !is_string($entry['tool_class']) || $entry['tool_class'] === '') {
            return "tool entry #{$i} is missing `tool_class`.";
        }
        return null;
    }

    /**
     * Empty / missing operations is legal — the operation default then
     * applies.
     *
     * @param  mixed $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseOperations(mixed $ops, int $i): array|ToolResult
    {
        if (!is_array($ops) || $ops === []) {
            return [];
        }
        $ops = SlimPayloadValidator::unwrapSingleItemArray($ops);
        if (!is_array($ops) || ($ops !== [] && !array_is_list($ops))) {
            return ToolResult::fail(
                self::CONFIGURE_TOOLS_ERR_PREFIX . "operations[{$i}] must be an array of `{name, enabled?, auto_approve?}`.",
            );
        }
        return $this->parseOperationRows($ops, $i);
    }

    /**
     * @param  list<mixed> $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseOperationRows(array $ops, int $i): array|ToolResult
    {
        $out = [];
        foreach ($ops as $j => $op) {
            if (!is_array($op) || !isset($op['name']) || !is_string($op['name']) || $op['name'] === '') {
                return ToolResult::fail(
                    self::CONFIGURE_TOOLS_ERR_PREFIX . "operations[{$i}][{$j}] must be `{name, enabled?, auto_approve?}`.",
                );
            }
            $out[] = [
                'name'         => $op['name'],
                'enabled'      => (bool) ($op['enabled'] ?? true),
                'auto_approve' => (bool) ($op['auto_approve'] ?? false),
            ];
        }
        return $out;
    }

    /**
     * Validate `settings` against the `#[ToolSetting]` declarations of the
     * entry's tool class, and return it in stored form.
     *
     * An unknown key is refused rather than dropped: silently ignoring
     * `{"allowed_sklls": [...]}` would leave the model believing a list landed
     * that did not, and it would then read a skill it still cannot read.
     *
     * @return array<string, mixed>|ToolResult
     */
    private function parseSettings(mixed $settings, string $toolClass, int $i, ?int $principalId): array|ToolResult
    {
        if ($settings === null || $settings === []) {
            return [];
        }
        if (!is_array($settings)) {
            return $this->settingsFailure($i, 'must be an object of `{setting_key: value}` pairs.');
        }

        $schema = self::settingsSchema($toolClass);
        $out = [];
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            $setting = $schema[$key] ?? null;
            if ($setting === null) {
                return $this->settingsFailure($i, sprintf(
                    "'%s' is not a setting on %s. %s",
                    $key,
                    $toolClass,
                    self::validKeys($schema),
                ));
            }
            if ($setting->type !== 'multi-select') {
                $out[$key] = $value;
                continue;
            }
            $names = self::stringList($value);
            if ($names === null) {
                return $this->settingsFailure($i, "'{$key}' must be an array of strings.");
            }
            if ($setting->resolveAs === 'skill') {
                $refusal = $this->invisibleSkillRefusal($key, $names, $i, $principalId);
                if ($refusal !== null) {
                    return $refusal;
                }
            }
            $out[$key] = json_encode($names, JSON_THROW_ON_ERROR);
        }
        return $out;
    }

    /**
     * The first submitted skill name this principal cannot see, as a refusal,
     * or null when every name is visible.
     *
     * Refuse rather than silently drop. A list that quietly shrank reads to the
     * model as the whole list landing — and the names it dropped are the ones it
     * is not entitled to have confirmed. The check can only ever pre-approve what
     * the operator could have granted by hand, so bounding it here is what keeps
     * the write from being a cross-tenant grant.
     *
     * @param list<string> $names
     */
    private function invisibleSkillRefusal(string $key, array $names, int $i, ?int $principalId): ?ToolResult
    {
        if ($this->skills === null) {
            return null;
        }

        $visible = [];
        foreach ($this->skills->getSkills($principalId) as $summary) {
            $visible[strtolower($summary->name)] = true;
        }

        foreach ($names as $name) {
            if (!isset($visible[strtolower(trim($name))])) {
                return $this->settingsFailure($i, sprintf(
                    "'%s' names '%s', which is not available to this principal. Read get_available_tools and pick from its `skills.visible` list.",
                    $key,
                    $name,
                ));
            }
        }

        return null;
    }

    /**
     * @return array<string, ToolSetting>
     */
    private static function settingsSchema(string $toolClass): array
    {
        $byKey = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            $byKey[$setting->key] = $setting;
        }
        return $byKey;
    }

    /**
     * @param array<string, ToolSetting> $schema
     */
    private static function validKeys(array $schema): string
    {
        if ($schema === []) {
            return 'It declares no settings.';
        }

        return 'Valid settings: ' . implode(', ', array_keys($schema)) . '.';
    }

    /**
     * The `string[]` a multi-select is stored as, or null when the value is not
     * one.
     *
     * @return list<string>|null
     */
    private static function stringList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return null;
            }
            $out[] = $entry;
        }

        return $out;
    }

    private function settingsFailure(int $i, string $message): ToolResult
    {
        return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . "settings[{$i}] {$message}");
    }
}
