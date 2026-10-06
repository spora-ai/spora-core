<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\LlmScalarCoercion;
use Spora\Tools\ToolSettingSchema;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The settings half of a `configure_tools` entry.
 *
 * Split from {@see ConfigurePlanParser}: this half needs the skill registry, to
 * check a principal's visible names against an `allowed_skills` write, which
 * neither the entry's shape nor its operations rows have any use for.
 */
final class ConfigurePlanSettingsParser
{
    use LlmScalarCoercion;

    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';

    /** The two `#[ToolSetting]` types that are not a plain value. */
    private const TYPE_PASSWORD     = 'password';
    private const TYPE_MULTI_SELECT = 'multi-select';

    /**
     * The two bounded scalar types: a flag and an enum.
     *
     * Both are plain values to the settings service — it stores whatever it
     * is handed — so nothing between here and the column would ever have
     * complained. `type: 'toggle'` therefore wrote the literal string
     * `"false"` into a boolean column (reads back as true, the exact defect
     * `AgentPatchValidator` exists to prevent), and `type: 'select'` accepted
     * a value outside the declared options, leaving a row the settings form
     * cannot render and its next save silently resets.
     */
    private const TYPE_TOGGLE       = 'toggle';
    private const TYPE_SELECT       = 'select';

    public function __construct(
        private readonly ?SkillProviderRegistry $skills = null,
    ) {}

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
    public function parseSettings(mixed $settings, string $toolClass, int $i, ?int $principalId): array|ToolResult
    {
        $pairs = $this->settingsPairs($settings, $i);
        if ($pairs instanceof ToolResult) {
            return $pairs;
        }

        $schema = [];
        foreach (ToolSettingSchema::collect($toolClass) as $declared) {
            $schema[$declared->key] = $declared;
        }
        $out = [];
        foreach ($pairs as $key => $value) {
            $known = $this->coerceSetting((string) $key, $value, $schema, $toolClass, $i, $principalId);
            if ($known instanceof ToolResult) {
                return $known;
            }
            $out[$key] = $known;
        }
        return $out;
    }

    /**
     * The submitted pairs, or the refusal for a `settings` that is not an
     * object. Absent and empty are both legal and both mean "no settings".
     *
     * @return array<array-key, mixed>|ToolResult
     */
    private function settingsPairs(mixed $settings, int $i): array|ToolResult
    {
        if ($settings === null || $settings === []) {
            return [];
        }
        if (!is_array($settings)) {
            return $this->settingsFailure($i, 'must be an object of `{setting_key: value}` pairs.');
        }

        return $settings;
    }

    /**
     * One setting's stored form, or the refusal that stops the write.
     *
     * Split out of {@see parseSettings()} so each rule reads on its own. Order is
     * load-bearing: an unknown key is refused before the credential check, and
     * the credential refusal comes before anything is written, because a tool
     * call must never be able to put a secret where it can read it back out of
     * its own recorded arguments.
     *
     * @return mixed|ToolResult
     */
    private function coerceSetting(
        string $key,
        mixed $value,
        array $schema,
        string $toolClass,
        int $i,
        ?int $principalId,
    ) {
        $setting = $schema[$key] ?? null;
        if ($setting === null) {
            $valid = $schema === []
                ? 'It declares no settings.'
                : 'Valid settings: ' . implode(', ', array_keys($schema)) . '.';

            return $this->settingsFailure($i, sprintf(
                "'%s' is not a setting on %s. %s",
                $key,
                $toolClass,
                $valid,
            ));
        }

        return match ($setting->type) {
            self::TYPE_PASSWORD     => $this->settingsFailure($i, sprintf(
                "'%s' is a credential. The operator sets it in the settings panel; a tool call must not be able to write one, nor read it back through the call's own arguments.",
                $key,
            )),
            self::TYPE_MULTI_SELECT => $this->coerceMultiSelect($key, $value, $setting, $i, $principalId),
            self::TYPE_TOGGLE       => $this->coerceToggle($key, $value, $i),
            self::TYPE_SELECT       => $this->coerceSelect($key, $value, $setting, $i),
            default                 => $value,
        };
    }

    /**
     * A toggle's stored form: a real bool, never the string it arrived as.
     *
     * Delegates to {@see LlmScalarCoercion::coerceBool()} for the same reason
     * {@see ConfigurePlanOperationsParser::enablementFlag()} does — a provider
     * that flattens scalars into strings would otherwise make `"false"` the
     * truthy value and write a revocation as an enablement. The blank-string
     * case is the shared helper's `"empty means false"`, which is the right
     * reading here: a toggle has no tri-state, so there is no "unspecified" to
     * protect.
     *
     * @return bool|ToolResult
     */
    private function coerceToggle(string $key, mixed $value, int $i): bool|ToolResult
    {
        $coerced = $this->coerceBool($value);
        if ($coerced === null) {
            return $this->settingsFailure($i, sprintf(
                "'%s' must be true or false, got %s. Send true / false, the string \"true\" / "
                . '"false", or 0 / 1 — a quoted value is read as the boolean it names, not as truthy.',
                $key,
                $this->describeValue($value),
            ));
        }
        return $coerced;
    }

    /**
     * A select's stored form: one of the keys the declaration itself lists.
     *
     * `options` is a key => label map, so the keys are the legal values and
     * the labels are presentation — only the key is ever stored or read
     * back. Anything outside it is refused rather than written, because the
     * settings form renders a select as a dropdown over exactly those keys:
     * an unlisted value is invisible there, and the operator's next save
     * overwrites it with the declared default. That reads to the model as
     * "landed, then reverted", which is the same silent-loss shape as a dead
     * override row.
     *
     * A `select` declared with no options at all can validate nothing, so
     * every value is refused — fail closed rather than write an unbounded
     * value into a column whose legal set is unknown.
     *
     * @return string|ToolResult
     */
    private function coerceSelect(string $key, mixed $value, ToolSetting $setting, int $i): string|ToolResult
    {
        $options = array_keys($setting->options);
        if ($options === []) {
            return $this->settingsFailure($i, sprintf(
                "'%s' is a select that declares no options, so no value can be checked against it.",
                $key,
            ));
        }
        if (is_string($value) && array_key_exists($value, $setting->options)) {
            return $value;
        }

        return $this->settingsFailure($i, sprintf(
            "'%s' must be one of: %s. Got %s.",
            $key,
            implode(', ', $options),
            $this->describeValue($value),
        ));
    }

    /**
     * A multi-select is stored JSON-encoded, so the entries are validated into a
     * list first and encoded here — a nested array would read back as "nothing
     * configured" in the settings panel and its next write would wipe the list.
     *
     * @return string|ToolResult
     */
    private function coerceMultiSelect(
        string $key,
        mixed $value,
        ToolSetting $setting,
        int $i,
        ?int $principalId,
    ): string|ToolResult {
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

        return json_encode($names, JSON_THROW_ON_ERROR);
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
     * What this actually guarantees is narrower than "only the principal's own
     * names". It refuses any name **no** provider returns, and
     * `FilesystemSkillProvider` — the only provider core ships — ignores
     * `$principalId` and returns every bundled skill. So a null principal still
     * sees them all and a bundled name is still accepted here. That is sound
     * rather than broken: a check that only ever refuses what no provider
     * offers cannot grant more than the operator could have granted by hand.
     *
     * The null-principal and absent-registry arms are therefore defensive
     * today rather than load-bearing — they become the tight principal-scoped
     * boundary the moment a plugin registers such a provider, and they must
     * already be closed by then. An absent registry refuses because it cannot
     * make the claim at all: a check that vanishes when the wiring is
     * incomplete is not a check.
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
            $isAgent = $resolveAs === 'agent';
            $typed = $isAgent ? is_int($entry) : is_string($entry);
            if (!$typed) {
                return null;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Mirrors the operations half's refusal shape, so a refusal on either half
     * of an entry reads the same way to the model.
     */
    private function settingsFailure(int $i, string $message): ToolResult
    {
        return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . "settings[{$i}] {$message}");
    }
}
