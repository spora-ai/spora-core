<?php

declare(strict_types=1);

namespace Spora\Services;

use Closure;
use Spora\Models\AgentToolOverride;
use Spora\Tools\SharedSettingsResolver;

/**
 * The resolution half of {@see ToolConfigService}: walking a tool's settings
 * down the cascade and, where asked, saying which level each value came from.
 *
 * The two public entry points walk the same levels in the same order and
 * differ only in what they return, so they live together and share the pieces
 * that would otherwise drift — the agent-override read and the
 * cleared-key-means-inherit rule are each written once and applied twice.
 * What each level *supplies* is read through the closures rather than through
 * this service, so the storage rules stay in `ToolConfigService`, which is the
 * only class permitted to touch the encrypted columns.
 */
final class EffectiveToolSettings
{
    /**
     * @param Closure(string): array<string, mixed> $globalSettings
     * @param Closure(string, int): array<string, mixed> $principalSettings
     */
    public function __construct(
        private readonly ToolConfigSchemaInspector $schema,
        private readonly ToolConfigPrincipalCascade $cascade,
        private readonly SharedSettingsResolver $sharedSettings,
        private readonly ToolConfigCryptographer $crypto,
        private readonly Closure $globalSettings,
        private readonly Closure $principalSettings,
    ) {}

    /**
     * Walk the cascade and return the settings a tool actually runs with.
     *
     * `schema defaults → shared group → global → group[0..N] → user-principal →
     * agent`. The group-principal rows are consulted in `principal.id`
     * ASCENDING order so the iteration is stable across calls; the
     * user-principal is iterated last so user-scoped settings win on conflict.
     *
     * The shared-group layer is the exception to how this reads top-down: it
     * is applied to the schema defaults *before* the tool's own rows are
     * merged, never appended to the finished array. Appending it would put the
     * group above the tool's global value and silently turn a fallback into an
     * override — the one inversion this method cannot tolerate.
     *
     * `?PrincipalContext` is the preferred shape — it carries the principal id
     * directly so we don't re-derive a user-principal from `currentUserId()`,
     * and the explicit context bypasses the group cascade (only the single
     * named principal is consulted). The legacy `?int $userId` parameter is
     * preserved at the front so existing call sites (tools, controllers) don't
     * have to be updated as part of the migration; new callers should pass an
     * explicit `PrincipalContext`.
     *
     * @param  bool $includeSharedSettings False for the `ToolStatus` path. The
     *         status derives `missing_required` from what this method returns,
     *         and a plugin group supplying the credential would make every tool
     *         that consumes it report itself configured the moment the plugin
     *         is installed — before the operator has entered anything. Kept
     *         here rather than in a second resolution so the two paths cannot
     *         drift apart in any of the levels above.
     * @return array<string, mixed>
     */
    public function getEffectiveSettings(
        string $toolClass,
        int $agentId,
        ?int $userId = null,
        ?PrincipalContext $context = null,
        bool $includeSharedSettings = true,
    ): array {
        $defaults = $this->schema->getSchemaDefaults($toolClass);
        if ($includeSharedSettings) {
            $defaults = $this->sharedSettings->applyToDefaults($toolClass, $defaults, $this->globalSettings);
        }

        $merged = ($this->globalSettings)($toolClass);

        foreach ($this->cascade->resolvePrincipalIds($userId, $context) as $principalId) {
            foreach (($this->principalSettings)($toolClass, $principalId) as $key => $value) {
                $merged[$key] = $value;
            }
        }

        foreach ($this->agentOverride($toolClass, $agentId) as $key => $value) {
            $merged[$key] = $value;
        }

        return $this->schema->normalizeMultiSelectValues($toolClass, $this->applyBase($merged, $defaults));
    }

    /**
     * Return effective settings annotated with their source.
     *
     * In the principals-and-groups model the source values are
     * `'global' | 'group' | 'principal' | 'agent' | 'default'`, plus
     * `'shared'` for a value that came from the plugin's shared settings
     * group. `'group'` is the label for any group-principal row consulted
     * through the cascade; `'principal'` continues to label the
     * user-principal level (the source name predates the user/group
     * split and matches the `tool_user_settings.principal_id` column).
     * `'shared'` is the plugin-declared credential group and is unrelated
     * to the group-principal axis — they are different meanings of the
     * word, which is why the plugin one needed its own label rather than
     * reusing `'group'`.
     * Because the cascade iterates `shared → global → group[0..N] →
     * principal → agent`, the source label for a key is determined by the
     * LAST level that overwrote it (last write wins), which keeps this
     * method in lockstep with {@see self::getEffectiveSettings()}.
     *
     * @return array<string, array{value: mixed, source: 'shared'|'global'|'group'|'principal'|'agent'|'default'}>
     */
    public function getEffectiveSettingsWithSource(string $toolClass, int $agentId, ?int $userId = null, ?PrincipalContext $context = null): array
    {
        [$cascadePrincipalIds, $userPrincipalId] = $this->cascade->resolvePrincipalIdsWithUserRef($userId, $context);

        $schemaDefaults = $this->schema->getSchemaDefaults($toolClass);
        $shared = $this->sharedSettings->applyToDefaults($toolClass, $schemaDefaults, $this->globalSettings);
        $fromGroup = $this->suppliedByGroup($toolClass);

        $result = [];
        foreach ($shared as $key => $value) {
            $result[$key] = ['value' => $value, 'source' => $this->baseSource($key, $fromGroup, $schemaDefaults)];
        }

        foreach (($this->globalSettings)($toolClass) as $key => $value) {
            $result[$key] = ['value' => $value, 'source' => 'global'];
        }

        foreach ($cascadePrincipalIds as $principalId) {
            $source = ($principalId === $userPrincipalId) ? 'principal' : 'group';
            foreach (($this->principalSettings)($toolClass, $principalId) as $key => $value) {
                $result[$key] = ['value' => $value, 'source' => $source];
            }
        }

        foreach ($this->agentOverride($toolClass, $agentId) as $key => $value) {
            $result[$key] = ['value' => $value, 'source' => 'agent'];
        }

        return $this->rebaseClearedKeys($result, $shared, $schemaDefaults);
    }

    /**
     * Return effective settings filtered to only those with exposeToLlm === true.
     * Each entry includes the human-readable label and the resolved value.
     *
     * @return array<string, array{label: string, value: mixed}>
     */
    public function getLlmToolSettings(string $toolClass, int $agentId, ?int $userId = null, ?PrincipalContext $context = null): array
    {
        $effective = $this->getEffectiveSettings($toolClass, $agentId, $userId, $context);

        // `$context` is forwarded, not dropped. The settings cascade has
        // already used it; the `resolveAs: 'skill'` projection needs it too, or
        // a provider-supplied skill is authorised by the `skill` tool and
        // simultaneously absent from the tool definition that would suggest it.
        return $this->schema->getLlmToolSettings($toolClass, $effective, $userId, $agentId, $context);
    }

    /**
     * Every key whose value must be masked before it leaves the process, for
     * `$toolClass` or for the shared group it consumes.
     *
     * Exposed rather than folded into the masking call because the
     * agent-override path masks a `{value, source}` pair key by key, and
     * duplicating the union there is how the two paths drifted apart once
     * already.
     *
     * @return list<string>
     */
    public function maskablePasswordKeys(string $toolClass): array
    {
        $passwordKeys = $this->schema->getPasswordKeys($toolClass);

        // A value the shared group supplied was encrypted under the *group's*
        // `#[ToolSetting(type: 'password')]` list, so the tool's own list is
        // not enough to mask it. A tool that declares the key as `text` —
        // a mistyping the schema cannot detect — would otherwise publish the
        // plugin-wide credential on routes that are not admin-gated.
        $groupClass = $this->sharedSettings->groupClassFor($toolClass);
        if ($groupClass !== null) {
            $passwordKeys = array_merge($passwordKeys, $this->schema->getPasswordKeys($groupClass));
        }

        return array_values(array_unique($passwordKeys));
    }

    /**
     * Whether the shared group has any stored value at all.
     *
     * Callers that persist a value at a level that outranks the group use this
     * to decide whether that level should be seeded at all. Seeding the schema
     * default would shadow the group; seeding the group's value would let it
     * cross the status boundary that deliberately resolves without it.
     */
    public function sharedGroupHasValues(string $toolClass): bool
    {
        return $this->suppliedByGroup($toolClass) !== [];
    }

    /**
     * The keys the shared group actually supplied.
     *
     * `applyToDefaults()` writes them into the same array, so testing the
     * merged result against the schema defaults cannot tell the two apart — a
     * group value that overrode a code default was labelled `default`,
     * contradicting the annotation contract and mis-rendering in the form
     * that reads `source`.
     *
     * @return list<string>
     */
    private function suppliedByGroup(string $toolClass): array
    {
        $groupClass = $this->sharedSettings->groupClassFor($toolClass);
        if ($groupClass === null) {
            return [];
        }

        return array_keys(array_filter(
            ($this->globalSettings)($groupClass),
            static fn(mixed $v): bool => $v !== null && $v !== '',
        ));
    }

    /**
     * Which layer a base-level value came from, for the annotation.
     *
     * @param list<string>         $fromGroup      keys the shared group supplied
     * @param array<string, mixed> $schemaDefaults the tool's declared defaults
     */
    private function baseSource(string $key, array $fromGroup, array $schemaDefaults): string
    {
        if (in_array($key, $fromGroup, true)) {
            return 'shared';
        }

        return array_key_exists($key, $schemaDefaults) ? 'default' : 'shared';
    }

    /**
     * @return array<string, mixed>
     */
    private function agentOverride(string $toolClass, int $agentId): array
    {
        $override = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        return $override === null
            ? []
            : $this->crypto->decodeSettings($toolClass, $override->getRawOriginal('settings'));
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function applyBase(array $merged, array $defaults): array
    {
        foreach ($defaults as $key => $defaultValue) {
            if (!isset($merged[$key])) {
                $merged[$key] = $defaultValue;
            }

            // `putGlobalSettings()` stores '' when the operator clears a key to
            // inherit. '' is *set*, so the branch above leaves it alone and the
            // tool would keep running on an empty credential instead of
            // reaching the shared value it was cleared for. An empty value
            // means inherit — but only when there is something below to
            // inherit, so a key with no default and no group keeps the ''
            // every non-consuming tool has always seen.
            if (($merged[$key] ?? null) === '' && $defaultValue !== null && $defaultValue !== '') {
                $merged[$key] = $defaultValue;
            }
        }

        return $merged;
    }

    /**
     * The cleared-key rule from {@see self::getEffectiveSettings()}: a level
     * above that left the key at '' supplied nothing, so the base value shows
     * through and the annotation reports the base's own source rather than
     * crediting the empty level.
     *
     * @param array<string, array{value: mixed, source: 'shared'|'global'|'group'|'principal'|'agent'|'default'}> $result
     * @param array<string, mixed>                                                                               $shared
     * @param array<string, mixed>                                                                               $schemaDefaults
     * @return array<string, array{value: mixed, source: 'shared'|'global'|'group'|'principal'|'agent'|'default'}>
     */
    private function rebaseClearedKeys(array $result, array $shared, array $schemaDefaults): array
    {
        foreach ($shared as $key => $value) {
            if (($result[$key]['value'] ?? null) === '') {
                $result[$key] = [
                    'value'  => $value,
                    'source' => array_key_exists($key, $schemaDefaults) ? 'default' : 'shared',
                ];
            }
        }

        return $result;
    }
}
