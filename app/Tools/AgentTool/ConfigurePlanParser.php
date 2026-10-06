<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\LlmScalarCoercion;
use Spora\Tools\ToolSchemaPresenter;
use Spora\Tools\ToolSettingSchema;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Validates a `configure_tools` payload into a plan, or refuses it whole.
 *
 * Split from {@see ConfigurePlanner}, which applies a plan. Parsing and applying
 * have genuinely different dependencies — this half needs the skill registry to
 * check a principal's visible names, the other needs the settings service to
 * write — and they fail differently: every refusal here happens before a single
 * write, so a rejected payload cannot have landed anything.
 *
 * The rules, in the order they are checked:
 *
 *  - **An entry's shape**, then its `enabled` flag, its operations, its settings.
 *  - **An operation name** the tool does not declare is refused, because a dead
 *    override row is invisible in the manifest and would read as "nothing landed".
 *  - **An unknown setting key** is refused rather than dropped, for the same
 *    reason: a silently ignored `{"allowed_sklls": [...]}` leaves the model
 *    believing a list landed, and it would then read a skill it still cannot.
 *  - **A `type: 'password'` setting** is refused outright. A credential is the
 *    one thing a tool call must not be able to write: the value would land in the
 *    call's own recorded arguments, so the agent could read back the key it just
 *    set. Credentials stay operator-only, through the settings panel.
 *  - **A `type: 'toggle'` value** is coerced to a real boolean before anything
 *    else, exactly like `enabled`: a provider that quotes its scalars would
 *    otherwise store the string `"false"` in a boolean column, which reads back
 *    as true.
 *  - **A `type: 'select'` value** must be one of the option keys the
 *    declaration itself lists. The settings form renders it as a dropdown over
 *    precisely those keys, so an unlisted value is invisible there and the
 *    operator's next save silently resets it.
 *  - **A skill name the executing principal cannot see** refuses the whole call.
 *    That check is a property of the write, so it lives here rather than on the
 *    tool hosting the setting: a write that cannot make the claim should not be
 *    able to grant the name either.
 *
 * `enabled` is tri-state: `true` enables, `false` disables, and an **absent** key
 * leaves enablement alone so a settings- or operations-only entry cannot grant a
 * tool as a side effect.
 */
final class ConfigurePlanParser
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

        $parts = $this->parseEntryParts($entry, $toolClass, $i, $principalId);

        return $parts instanceof ToolResult
            ? $parts
            : [
                'tool_class' => $toolClass,
                'enable'     => $parts['enable'],
                'operations' => $parts['operations'],
                'settings'   => $parts['settings'],
            ];
    }

    /**
     * The three independently-refusable halves of an entry, in the order they
     * are checked. Enablement first so a malformed flag is reported before the
     * caller is told about a misspelled operation.
     *
     * @param  array<string, mixed> $entry
     * @return array{enable: bool|null, operations: list<array{name: string, enabled: bool, auto_approve: bool}>, settings: array<string, mixed>}|ToolResult
     */
    private function parseEntryParts(array $entry, string $toolClass, int $i, ?int $principalId): array|ToolResult
    {
        $parsers = [
            'enable'     => fn(): bool|null|ToolResult
                => $this->enablementFlag($entry, 'enabled', "tool entry #{$i}"),
            'operations' => fn(): array|ToolResult
                => $this->parseOperations($entry['operations'] ?? [], $toolClass, $i),
            'settings'   => fn(): array|ToolResult
                => $this->parseSettings($entry['settings'] ?? [], $toolClass, $i, $principalId),
        ];

        // Evaluated in declaration order and short-circuits on the first
        // refusal, so a malformed flag is still reported before a misspelled
        // operation. A loop rather than three sequential guards because the
        // three parsers share a shape and differ only by name.
        $out = [];
        foreach ($parsers as $key => $parse) {
            $value = $parse();
            if ($value instanceof ToolResult) {
                return $value;
            }
            $out[$key] = $value;
        }

        return $out;
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
     * A non-array is *refused*, not dropped, which is what this class does
     * everywhere else and what makes the sibling `settings` check consistent:
     * `{"operations": "now"}` silently returning "no operations" lets the
     * whole call report success having applied nothing, and the model reads
     * that as an enablement that landed. Absent, `null` and `[]` all still
     * mean "inherit the operation defaults"; only a value that claims to be
     * an operations list and is not one is an error.
     *
     * @param  mixed $ops
     * @return list<array{name: string, enabled: bool, auto_approve: bool}>|ToolResult
     */
    private function parseOperations(mixed $ops, string $toolClass, int $i): array|ToolResult
    {
        if ($ops === null || $ops === []) {
            return [];
        }
        if (!is_array($ops)) {
            return $this->operationsFailure($i);
        }
        $ops = SlimPayloadValidator::unwrapSingleItemArray($ops);
        if (!is_array($ops) || ($ops !== [] && !array_is_list($ops))) {
            return $this->operationsFailure($i);
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
            $row = $this->parseOperationRow($op, $declared, $toolClass, "operations[{$i}][{$j}]");
            if ($row instanceof ToolResult) {
                return $row;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param  array<string, true> $declared
     * @return array{name: string, enabled: bool, auto_approve: bool}|ToolResult
     */
    private function parseOperationRow(mixed $op, array $declared, string $toolClass, string $at): array|ToolResult
    {
        $name = $this->operationName($op, $declared, $toolClass, $at);
        if ($name instanceof ToolResult) {
            return $name;
        }

        $flags = $this->operationFlags($op, $at);
        if ($flags instanceof ToolResult) {
            return $flags;
        }

        return ['name' => $name, 'enabled' => $flags['enabled'], 'auto_approve' => $flags['auto_approve']];
    }

    /**
     * The operation's name, once it is known to be a non-empty string that the
     * tool actually declares.
     *
     * The declaration check is skipped for a class that declares no operations
     * or cannot be reflected against — a plugin tool that is not loaded is not
     * grounds for refusing every name it might legitimately declare.
     *
     * @param  array<string, true> $declared
     * @return string|ToolResult
     */
    private function operationName(mixed $op, array $declared, string $toolClass, string $at): string|ToolResult
    {
        $name = is_array($op) && is_string($op['name'] ?? null) ? $op['name'] : '';
        if ($name === '') {
            return ToolResult::fail(
                self::CONFIGURE_TOOLS_ERR_PREFIX . "{$at} must be `{name, enabled?, auto_approve?}`.",
            );
        }
        if ($declared !== [] && !isset($declared[$name])) {
            return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . sprintf(
                "%s names '%s', which is not an operation on %s. Available operations: %s.",
                $at,
                $name,
                $toolClass,
                implode(', ', array_keys($declared)),
            ));
        }

        return $name;
    }

    /**
     * @param  array<string, mixed> $op
     * @return array{enabled: bool, auto_approve: bool}|ToolResult
     */
    private function operationFlags(array $op, string $at): array|ToolResult
    {
        $enabled = $this->enablementFlag($op, 'enabled', $at) ?? true;
        if ($enabled instanceof ToolResult) {
            return $enabled;
        }
        $autoApprove = $this->enablementFlag($op, 'auto_approve', $at) ?? false;
        if ($autoApprove instanceof ToolResult) {
            return $autoApprove;
        }

        return ['enabled' => $enabled, 'auto_approve' => $autoApprove];
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
     * Delegates to {@see LlmScalarCoercion::coerceBool()} so every tool
     * accepts the same set of scalar spellings — some providers quote their
     * booleans, and a surface that refused those would let a model grant a
     * tool but never revoke one. Nothing here is cast: the `(bool)` cast
     * this replaced read `"false"` as **true**, so a quoted revocation
     * enabled the tool it was meant to remove.
     *
     * One local rule on top. A blank string is treated as *absent* rather
     * than as `false`, unlike the shared helper. Tri-state makes "no
     * change" representable here, so a malformed empty value should not be
     * read as a revocation — where there is no tri-state (a plain boolean
     * setting) the shared helper's `"empty means false"` stands.
     *
     * @param  array<string, mixed> $entry
     * @return bool|null|ToolResult
     */
    private function enablementFlag(array $entry, string $key, string $at): bool|null|ToolResult
    {
        $raw = $entry[$key] ?? null;
        if (!array_key_exists($key, $entry) || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        $coerced = $this->coerceBool($raw);

        if ($coerced !== null) {
            return $coerced;
        }

        return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . sprintf(
            "%s '%s' must be true or false, got %s. Send true / false, or the string "
            . '"true" / "false" — a quoted value is read as the boolean it names, not as truthy.',
            $at,
            $key,
            $this->describeValue($raw),
        ));
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
     * {@see enablementFlag()} does — a provider that flattens scalars into
     * strings would otherwise make `"false"` the truthy value and write a
     * revocation as an enablement. The blank-string case is the shared
     * helper's `"empty means false"`, which is the right reading here: a
     * toggle has no tri-state, so there is no "unspecified" to protect.
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

    private function settingsFailure(int $i, string $message): ToolResult
    {
        return ToolResult::fail(self::CONFIGURE_TOOLS_ERR_PREFIX . "settings[{$i}] {$message}");
    }

    /**
     * Mirrors {@see settingsFailure()}'s shape, so a refusal on either half
     * of an entry reads the same way to the model.
     */
    private function operationsFailure(int $i): ToolResult
    {
        return ToolResult::fail(
            self::CONFIGURE_TOOLS_ERR_PREFIX . "operations[{$i}] must be an array of `{name, enabled?, auto_approve?}`.",
        );
    }
}
