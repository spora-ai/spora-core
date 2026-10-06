<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Skills\SkillProviderRegistry;
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
 * Each independently-refusable half of an entry lives in
 * {@see ConfigurePlanOperationsParser} and {@see ConfigurePlanSettingsParser};
 * this class owns the entry's shape and the order the halves are checked in.
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
    private const CONFIGURE_TOOLS_ERR_PREFIX = 'configure_tools: ';

    private readonly ConfigurePlanOperationsParser $operations;

    private readonly ConfigurePlanSettingsParser $settings;

    public function __construct(
        ?SkillProviderRegistry $skills = null,
    ) {
        $this->operations = new ConfigurePlanOperationsParser();
        $this->settings = new ConfigurePlanSettingsParser($skills);
    }

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
                => $this->operations->enablementFlag($entry, 'enabled', "tool entry #{$i}"),
            'operations' => fn(): array|ToolResult
                => $this->operations->parseOperations($entry['operations'] ?? [], $toolClass, $i),
            'settings'   => fn(): array|ToolResult
                => $this->settings->parseSettings($entry['settings'] ?? [], $toolClass, $i, $principalId),
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
}
