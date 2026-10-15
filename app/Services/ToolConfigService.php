<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Core\SecurityManagerInterface;
use Spora\Models\Agent;
use Spora\Models\AgentToolOverride;
use Spora\Models\ToolConfiguration;
use Spora\Models\ToolUserSetting;
use Spora\Tools\SharedSettingsResolver;

/**
 * The ONLY class permitted to read or write tool_configurations.settings
 * and agent_tool_overrides.settings columns.
 *
 * The Eloquent models have a guard accessor that throws LogicException on direct
 * `settings` access — all reads/writes must funnel through this service.
 *
 * This class is a thin facade. The schema/crypto/name responsibilities are
 * delegated to ToolConfigSchemaInspector, ToolConfigCryptographer and
 * ToolConfigNameResolver; the CRUD + effective-resolution orchestration
 * lives here.
 *
 * Not declared `final` to preserve Mockery::mock(ToolConfigService::class) in
 * existing tool tests (Mockery cannot mock final classes). The split itself
 * resolves the php:S1448 (too many methods) violation that motivated the
 * refactor. If a future change makes the class `final`, the consumer-side
 * type hints should be switched to ToolConfigServiceInterface.
 *
 * Migration 0067 renamed `tool_user_settings.user_id` to
 * `tool_user_settings.principal_id`. The effective-settings cascade
 * is `schema defaults → shared group → global → group[0..N] → user-principal → agent`.
 * The "shared group" level is the plugin-declared credential group
 * ({@see \Spora\Tools\SharedSettingsResolver}) and is absent for every tool
 * that names no group.
 * When the caller passes an explicit `PrincipalContext` only that
 * single principal id is consulted (preserves the legacy single-principal
 * semantics for callers that want to bypass the user/group cascade).
 * When `?int $userId` is supplied without a `PrincipalContext`, the
 * cascade looks up the user-principal + every group-principal the user
 * belongs to via {@see PrincipalService::principalIdsForUser()} and walks
 * them in `groups by id ascending, then user-principal` order so the
 * user-principal wins on conflict (last write wins).
 */
class ToolConfigService implements ToolConfigServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private readonly ToolConfigCryptographer $crypto;

    private readonly ToolConfigNameResolver $nameResolver;

    private readonly ToolConfigSchemaInspector $schema;

    private readonly ToolConfigPrincipalCascade $cascade;

    private readonly SharedSettingsResolver $sharedSettings;

    private readonly EffectiveToolSettings $effective;

    /**
     * @param list<string>                    $toolClasses
     * @param ToolConfigOverrides|null        $overrides The collaborators to use
     *        instead of building them. A null value is equivalent to an
     *        all-null instance, which is what every test call site relies on.
     */
    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $toolClasses = [],
        ?ToolConfigOverrides $overrides = null,
    ) {
        $overrides ??= new ToolConfigOverrides();

        // Without the resolver the inspector's LLM-facing `allowed_target_agents`
        // enumerates "#id" placeholders; null is fine for tests, the DI runtime
        // always passes the resolver.
        $this->schema = $overrides->schema ?? new ToolConfigSchemaInspector(principalResolver: $overrides->principalResolver);
        $this->crypto = new ToolConfigCryptographer($security, $this->schema->getPasswordKeys(...));
        // The shared-settings classes go in too: `ToolController` writes through
        // this resolver, and without them a group id resolves to nothing — so
        // the shared credential the feature exists to store had no reachable
        // write path at all.
        $this->nameResolver = new ToolConfigNameResolver($logger, $toolClasses, $overrides->sharedSettings?->settingsClasses() ?? []);
        $this->cascade = new ToolConfigPrincipalCascade(
            $overrides->principalService ?? new PrincipalService($overrides->principalResolver ?? new PrincipalResolver()),
            $overrides->groupCascadeEnabled,
        );
        $this->sharedSettings = $overrides->sharedSettings ?? new SharedSettingsResolver();
        // Always built here rather than injected: it reads its levels through the
        // closures below, which are bound to this instance, so a pre-built one
        // could not be supplied from the container without a circular
        // dependency on the service it reads from.
        $this->effective = new EffectiveToolSettings(
            $this->schema,
            $this->cascade,
            $this->sharedSettings,
            $this->crypto,
            $this->getGlobalSettings(...),
            $this->getPrincipalSettings(...),
        );
    }

    /**
     * Load global settings for a tool class, decrypting password fields.
     *
     * @return array<string, mixed>
     */
    public function getGlobalSettings(string $toolClass): array
    {
        $model = ToolConfiguration::where('tool_class', $toolClass)->first();

        if ($model === null) {
            return [];
        }

        return $this->crypto->decodeSettings($toolClass, $model->getRawOriginal('settings'));
    }

    /**
     * Persist global settings for a tool class.
     * Settings are wholesale-encrypted; omitted keys are merged from existing stored values.
     */
    public function putGlobalSettings(string $toolClass, array $settings): void
    {
        $toolName = $this->nameResolver->getToolName($toolClass);

        $existing = $this->getGlobalSettings($toolClass);

        foreach ($settings as $key => $value) {
            if ($value === '***' && array_key_exists($key, $existing)) {
                $settings[$key] = $existing[$key];
            }
        }

        $merged   = array_merge($existing, $settings);
        $merged   = $this->crypto->filterSettings($toolClass, $merged);

        $encrypted = $this->crypto->encryptSettings($toolClass, $merged);

        $existingRecord = ToolConfiguration::where('tool_class', $toolClass)->first();

        if ($existingRecord !== null) {
            Capsule::table('tool_configurations')
                ->where('tool_class', $toolClass)
                ->update([
                    'tool_name'  => $toolName,
                    'settings'   => $encrypted,
                    'updated_at' => date(self::DATETIME_FORMAT),
                ]);
        } else {
            Capsule::table('tool_configurations')->insert([
                'tool_class'  => $toolClass,
                'tool_name'   => $toolName,
                'settings'    => $encrypted,
                'created_at'  => date(self::DATETIME_FORMAT),
                'updated_at' => date(self::DATETIME_FORMAT),
            ]);
        }
    }

    /**
     * Load principal-scoped settings for a tool class.
     *
     * @return array<string, mixed>
     */
    public function getPrincipalSettings(string $toolClass, int $principalId): array
    {
        $model = ToolUserSetting::where('principal_id', $principalId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($model === null) {
            return [];
        }

        return $this->crypto->decodeSettings($toolClass, $model->getRawOriginal('settings'));
    }

    /**
     * Persist principal-scoped settings for a tool class.
     *
     * @return array<string, mixed> Decrypted settings (for immediate use)
     */
    public function putPrincipalSettings(string $toolClass, int $principalId, array $settings): array
    {
        $existingSettings = $this->getPrincipalSettings($toolClass, $principalId);

        foreach ($settings as $key => $value) {
            if ($value === '***' && array_key_exists($key, $existingSettings)) {
                $settings[$key] = $existingSettings[$key];
            }
        }

        $merged    = array_merge($existingSettings, $settings);
        $merged    = $this->crypto->filterSettings($toolClass, $merged);
        $encrypted = $this->crypto->encryptSettings($toolClass, $merged);

        $record = ToolUserSetting::where('principal_id', $principalId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($record !== null) {
            Capsule::table('tool_user_settings')
                ->where('principal_id', $principalId)
                ->where('tool_class', $toolClass)
                ->update([
                    'settings'   => $encrypted,
                    'updated_at' => date(self::DATETIME_FORMAT),
                ]);
        } else {
            Capsule::table('tool_user_settings')->insert([
                'principal_id' => $principalId,
                'tool_class'   => $toolClass,
                'settings'     => $encrypted,
                'created_at'   => date(self::DATETIME_FORMAT),
                'updated_at'   => date(self::DATETIME_FORMAT),
            ]);
        }

        return $this->crypto->decodeSettings($toolClass, $encrypted);
    }

    /**
     * Return effective settings: global defaults merged with principal
     * settings and agent-specific overrides.
     *
     * Cascade: schema defaults → shared group → global settings →
     * group[0..N] settings → user-principal settings → agent overrides.
     * The group-principal rows are consulted in `principal.id` ASCENDING
     * order so the iteration is stable across calls; the user-principal is
     * iterated last so user-scoped settings win on conflict.
     *
     * The shared-group layer is the exception to how this reads top-down: it
     * is applied to the schema defaults *before* the tool's own rows are
     * merged, never appended to the finished array. Appending it would put
     * the group above the tool's global value and silently turn a fallback
     * into an override — the one inversion this method cannot tolerate.
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
        return $this->effective->getEffectiveSettings($toolClass, $agentId, $userId, $context, $includeSharedSettings);
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
        return $this->schema->maskForApi($settings, $toolClass, $this->effective->maskablePasswordKeys($toolClass));
    }

    /**
 * Every key whose value must be masked before it leaves the process, for
 * `$toolClass` or for the shared group it consumes.
 *
 * Exposed rather than folded into {@see self::maskForApi()} because the
 * agent-override path masks a `{value, source}` pair key by key, and
 * duplicating the union there is how the two paths drifted apart once
 * already. {@see EffectiveToolSettings} owns the union because it is a
 * shared-group question; this stays the way in, since that class reads its
 * levels through this service and so cannot be built without it.
 *
 * @return list<string>
 */
    public function maskablePasswordKeys(string $toolClass): array
    {
        return $this->effective->maskablePasswordKeys($toolClass);
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
        return $this->effective->sharedGroupHasValues($toolClass);
    }

    /**
     * Persist agent-specific overrides.
     * Settings are merged with existing stored values; null/empty values break inheritance.
     */
    public function putAgentOverride(string $toolClass, int $agentId, array $settings): void
    {
        $existing = $this->getRawAgentOverride($toolClass, $agentId);
        $agentSettings = [];

        foreach ($settings as $key => $value) {
            if ($value === '***' && array_key_exists($key, $existing)) {
                $value = $existing[$key];
            }
            $agentSettings[$key] = $value;
        }

        $merged = array_merge($existing, $agentSettings);

        $filtered = $this->crypto->filterSettings($toolClass, $merged);
        $filtered = array_filter($filtered, fn($v) => $v !== null && $v !== '');

        $encrypted = $this->crypto->encryptSettings($toolClass, $filtered);

        $existingRecord = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($existingRecord !== null) {
            Capsule::table('agent_tool_overrides')
                ->where('agent_id', $agentId)
                ->where('tool_class', $toolClass)
                ->update([
                    'settings'   => $encrypted,
                    'updated_at' => date(self::DATETIME_FORMAT),
                ]);
        } else {
            Capsule::table('agent_tool_overrides')->insert([
                'agent_id'   => $agentId,
                'tool_class' => $toolClass,
                'settings'   => $encrypted,
                'created_at' => date(self::DATETIME_FORMAT),
                'updated_at' => date(self::DATETIME_FORMAT),
            ]);
        }
    }

    /**
     * Delete the agent-specific override for a tool.
     */
    public function deleteAgentOverride(string $toolClass, int $agentId): void
    {
        AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->delete();
    }

    /**
     * Delete global settings for a tool class.
     */
    public function deleteGlobalSettings(string $toolClass): void
    {
        ToolConfiguration::where('tool_class', $toolClass)->delete();
    }

    /**
     * Delete principal-specific settings for a tool class.
     */
    public function deletePrincipalSettings(string $toolClass, int $principalId): void
    {
        ToolUserSetting::where('principal_id', $principalId)
            ->where('tool_class', $toolClass)
            ->delete();
    }

    /**
     * Encrypt a settings array to a storage string.
     * Only password fields are encrypted per-field; all other fields are stored as plain JSON.
     *
     * @param array<string, mixed> $settings
     */
    public function encryptSettings(string $toolClass, array $settings): string
    {
        return $this->crypto->encryptSettings($toolClass, $settings);
    }

    /**
     * Decrypt a storage string back to a plain settings array.
     *
     * @return array<string, mixed>
     */
    public function decryptSettings(string $storageString): array
    {
        return $this->crypto->decryptSettings($storageString);
    }

    /**
     * Resolve a tool identifier (from #[Tool(name:)]) to its fully-qualified PHP class name.
     *
     * @deprecated use the injected {@see ToolConfigService::nameResolver}
     *             directly via its own injection point.
     */
    public function resolveToolClass(string $toolName): ?string
    {
        return $this->nameResolver->resolveToolClass($toolName);
    }

    /**
     * Return all registered tool class names.
     *
     * @return list<string>
     *
     * @deprecated see {@see ToolConfigService::resolveToolClass()}
     */
    public function getRegisteredToolClasses(): array
    {
        return $this->nameResolver->getRegisteredToolClasses();
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
     * LAST level that overwrote it (last write wins), which keeps
     * `getEffectiveSettingsWithSource` in lockstep with
     * {@see self::getEffectiveSettings()}.
     *
     * @return array<string, array{value: mixed, source: 'shared'|'global'|'group'|'principal'|'agent'|'default'}>
     */
    public function getEffectiveSettingsWithSource(string $toolClass, int $agentId, ?int $userId = null, ?PrincipalContext $context = null): array
    {
        return $this->effective->getEffectiveSettingsWithSource($toolClass, $agentId, $userId, $context);
    }

    /**
     * Return only the raw agent override (without merging global defaults).
     * Used by the frontend to show which fields are actually stored in the override.
     *
     * @return array<string, mixed>
     */
    public function getRawAgentOverride(string $toolClass, int $agentId): array
    {
        $override = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($override === null) {
            return [];
        }

        return $this->crypto->decodeSettings($toolClass, $override->getRawOriginal('settings'));
    }

    /**
     * Return effective settings filtered to only those with exposeToLlm === true.
     * Each entry includes the human-readable label and the resolved value.
     *
     * @return array<string, array{label: string, value: mixed}>
     */
    public function getLlmToolSettings(string $toolClass, int $agentId, ?int $userId = null, ?PrincipalContext $context = null): array
    {
        return $this->effective->getLlmToolSettings($toolClass, $agentId, $userId, $context);
    }
}
