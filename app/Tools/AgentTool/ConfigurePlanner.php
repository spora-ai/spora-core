<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ToolSchemaPresenter;
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
 * the name either. It fails closed — a null principal and an absent registry
 * both refuse rather than wave the write through.
 *
 * A `type: 'password'` setting is refused outright. A credential is the one
 * thing a tool call should not be able to write: the value would land in the
 * call's own recorded arguments, so the agent could read back the key it just
 * set. Credentials stay operator-only, through the settings panel.
 *
 * `enabled` is tri-state: `true` enables, `false` disables, and an **absent**
 * key leaves enablement alone so a settings- or operations-only entry cannot
 * grant a tool as a side effect. Only a real boolean is accepted — a model
 * that emits the string `"false"` would otherwise be read as truthy and
 * *enable* the tool on a revocation request, so a non-boolean is refused
 * rather than guessed at. An unknown operation name is refused for the same
 * reason an unknown setting key is: a dead override row is invisible in the
 * manifest, so a typo would read to the caller as "nothing landed".
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
     * @return list<array{tool_class: string, enable: bool|null, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}>|ToolResult
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
     * @param  list<array{tool_class: string, enable: bool|null, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}> $plan
     */
    public function apply(int $agentId, int $userId, array $plan): void
    {
        foreach ($plan as $step) {
            if ($step['enable'] === true) {
                $this->toolSettings->enableTool($agentId, $userId, $step['tool_class']);
            } elseif ($step['enable'] === false) {
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
     * @return array{tool_class: string, enable: bool|null, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}|ToolResult
     */
    private function parseEntry(mixed $entry, int $i, ?int $principalId): array|ToolResult
    {
        $shapeFail = $this->shapeEntryFailure($entry, $i);
        if ($shapeFail !== null) {
            return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . $shapeFail);
        }
        $toolClass = (string) ($entry['tool_class'] ?? '');

        $enable = $this->enablementFlag($entry, 'enabled', "tool entry #{$i}");
        if ($enable instanceof ToolResult) {
            return $enable;
        }

        $operations = $this->parseOperations($entry['operations'] ?? [], $toolClass, $i);
        if ($operations instanceof ToolResult) {
            return $operations;
        }
        $settings = $this->parseSettings($entry['settings'] ?? [], $toolClass, $i, $principalId);
        if ($settings instanceof ToolResult) {
            return $settings;
        }
        return [
            'tool_class' => $toolClass,
            'enable'     => $enable,
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
    private function parseOperations(mixed $ops, string $toolClass, int $i): array|ToolResult
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
        return $this->parseOperationRows($ops, $toolClass, $i);
    }

    /**
     * @param  list<mixed> $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseOperationRows(array $ops, string $toolClass, int $i): array|ToolResult
    {
        $declared = self::declaredOperationNames($toolClass);
        $out = [];
        foreach ($ops as $j => $op) {
            $at = "operations[{$i}][{$j}]";
            if (!is_array($op) || !isset($op['name']) || !is_string($op['name']) || $op['name'] === '') {
                return ToolResult::fail(
                    self::CONFIGURE_TOOLS_ERR_PREFIX . "{$at} must be `{name, enabled?, auto_approve?}`.",
                );
            }
            if ($declared !== [] && !isset($declared[$op['name']])) {
                return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . sprintf(
                    "%s names '%s', which is not an operation on %s. Available operations: %s.",
                    $at,
                    $op['name'],
                    $toolClass,
                    implode(', ', array_keys($declared)),
                ));
            }

            $enabled = $this->enablementFlag($op, 'enabled', $at) ?? true;
            if ($enabled instanceof ToolResult) {
                return $enabled;
            }
            $autoApprove = $this->enablementFlag($op, 'auto_approve', $at) ?? false;
            if ($autoApprove instanceof ToolResult) {
                return $autoApprove;
            }

            $out[] = [
                'name'         => $op['name'],
                'enabled'      => $enabled,
                'auto_approve' => $autoApprove,
            ];
        }
        return $out;
    }

    /**
     * The operation names a tool class actually declares, keyed for lookup.
     *
     * Empty when the class declares no operations or cannot be reflected
     * against. Callers must then skip validation rather than refuse every
     * name — a plugin tool that is not loaded is not grounds for a
     * false refusal.
     *
     * @return array<string, true>
     */
    private static function declaredOperationNames(string $toolClass): array
    {
        $names = [];
        foreach (ToolSchemaPresenter::summarize($toolClass)['operations'] as $op) {
            if ($op['name'] !== '') {
                $names[$op['name']] = true;
            }
        }
        return $names;
    }

    /**
     * A tri-state enablement flag: `true`, `false`, or null when the key is
     * absent and enablement should be left alone.
     *
     * Only a real boolean is accepted. A provider that emits the string
     * `"false"` would be read as truthy by a `(bool)` cast and *enable* the
     * tool on a revocation request, and a non-boolean cannot be told apart
     * from an absent key — so it is refused rather than guessed at.
     *
     * @param  array<string, mixed> $entry
     * @return bool|null|ToolResult
     */
    private function enablementFlag(array $entry, string $key, string $at): bool|null|ToolResult
    {
        if (!array_key_exists($key, $entry)) {
            return null;
        }
        $raw = $entry[$key];
        if (!is_bool($raw)) {
            return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . sprintf(
                "%s '%s' must be true or false, got %s.%s",
                $at,
                $key,
                self::describeValue($raw),
                is_string($raw) ? ' A quoted "false" would be read as true here — send a real boolean.' : '',
            ));
        }
        return $raw;
    }

    private static function describeValue(mixed $raw): string
    {
        return match (true) {
            $raw === null  => 'null',
            is_bool($raw)  => $raw ? 'true' : 'false',
            is_string($raw) => 'the string "' . $raw . '"',
            is_int($raw), is_float($raw) => 'the number ' . $raw,
            default => get_debug_type($raw),
        };
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
            if ($setting->type === 'password') {
                return $this->settingsFailure($i, sprintf(
                    "'%s' is a credential. The operator sets it in the settings panel; a tool call must not be able to write one, nor read it back through the call's own arguments.",
                    $key,
                ));
            }
            if ($setting->type !== 'multi-select') {
                $out[$key] = $value;
                continue;
            }
            $names = self::idOrNameList($value, $setting->resolveAs);
            if ($names === null) {
                return $this->settingsFailure($i, $setting->resolveAs === 'agent'
                    ? "'{$key}' must be an array of agent ids."
                    : "'{$key}' must be an array of strings.");
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
     * Fails closed in both directions. A null `$principalId` resolves no
     * principal, so a provider that scopes by one sees nothing and every name is
     * refused. An absent registry refuses too: it cannot make the claim at all,
     * and a check that vanishes when the wiring is incomplete is not a check.
     *
     * @param list<string> $names
     */
    private function invisibleSkillRefusal(string $key, array $names, int $i, ?int $principalId): ?ToolResult
    {
        if ($this->skills === null) {
            return $this->settingsFailure($i, sprintf(
                "'%s' cannot be written: the skill registry is unavailable, so these names cannot be checked against the principal's visible set.",
                $key,
            ));
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
     * A multi-select's stored entries, or null when the value is not one.
     *
     * A `resolveAs: 'agent'` multi-select is stored as `int[]`, so that is the
     * only shape accepted for it — a string there would be the `id` the admin
     * panel never writes, and the form's own `normalizeAgentIdList` would have
     * to paper over it. Everything else is a list of names.
     *
     * @return list<string>|list<int>|null
     */
    private static function idOrNameList(mixed $value, string $resolveAs): array|null
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $entry) {
            if ($resolveAs === 'agent') {
                if (!is_int($entry)) {
                    return null;
                }
                $out[] = $entry;
                continue;
            }
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
