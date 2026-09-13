<?php

declare(strict_types=1);

namespace Spora\Services;

use DateTimeInterface;
use Spora\Http\Exceptions\SpeechProviderConfigException;
use Spora\Models\Principal;
use Spora\Models\ToolConfiguration;
use Spora\Models\ToolUserSetting;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextRegistry;

/**
 * CRUD orchestrator for speech-to-text provider configurations.
 *
 * The controller stays a thin HTTP layer; this service owns auth,
 * scope resolution, schema validation, and the (scope, table) mapping
 * so callers can reason about the storage rules from one place:
 *
 *   - `scope = 'global'` → `tool_configurations` rows (keyed by
 *     `tool_class`; admin-only writes via {@see ToolConfigService::putGlobalSettings()})
 *   - `scope = 'user'`   → `tool_user_settings` rows keyed by
 *     (`tool_class`, `principal_id`); the user-principal id is resolved
 *     on demand via {@see PrincipalService::ensureUserPrincipal()}
 *   - `scope = 'group'`  → `tool_user_settings` rows keyed by
 *     (`tool_class`, `principal_id`) where `principal_id` is the
 *     group's group-principal id; the controller enforces that the
 *     caller is group admin OR global admin
 *
 * The `ConfigResource` wire shape mirrors what the SPA needs to render
 * the settings page: `{id, provider_class, provider_display_name,
 * scope, display_name, settings, principal_id, created_at, updated_at}`.
 * Password fields are masked via {@see ToolConfigSchemaInspector::maskForApi()}
 * so the round-trip follows the `"***"` convention the existing
 * `ToolController` uses.
 *
 * Per-provider-class schema reflection + required/regex enforcement
 * lives in {@see SpeechProviderConfigValidator} so this class stays
 * under the SonarCloud S1448 20-method ceiling.
 *
 * Singleton scope is `request`-lifetime (the DI container builds a new
 * instance per resolve); the underlying `ToolConfigService` is the
 * shared facade over the storage tables.
 */
final class SpeechProviderConfigService
{
    public function __construct(
        private readonly ToolConfigService $toolConfigService,
        private readonly SpeechToTextRegistry $registry,
        private readonly PrincipalService $principalService,
        private readonly SpeechProviderConfigValidator $validator,
        private readonly ToolConfigIdResolver $idResolver = new ToolConfigIdResolver(),
    ) {}

    /**
     * List the configs the caller can see.
     *
     *  - admin: every global config (one per registered provider class
     *    that has a row in `tool_configurations`) PLUS the admin's own
     *    user-scoped configs (rows in `tool_user_settings` keyed by the
     *    admin's user-principal). Admins may keep a personal override
     *    alongside the global default.
     *  - non-admin: only the caller's own user-scoped configs. Non-admins
     *    never see globals via this endpoint.
     *
     * When `$groupId` is provided, the list is narrowed to that group's
     * configs (admin/non-admin members can read their group's config;
     * group admins and global admins can read+edit it).
     *
     * @return list<array<string, mixed>>
     */
    public function listConfigs(int $userId, bool $isAdmin, ?int $groupId = null): array
    {
        if ($groupId !== null) {
            return $this->listGroupConfigs($userId, $isAdmin, $groupId);
        }

        $rows = [];

        if ($isAdmin) {
            foreach ($this->registry->all() as $provider) {
                $globalId = $this->idResolver->globalConfigId($provider::class);
                if ($globalId === null) {
                    continue;
                }
                $rows[] = $this->buildConfigResource(
                    rowId: $globalId,
                    providerClass: $provider::class,
                    scope: 'global',
                    settings: $this->toolConfigService->getGlobalSettings($provider::class),
                    principalId: null,
                    createdAt: $this->fetchCreatedAt($provider::class, scope: 'global'),
                    updatedAt: $this->fetchUpdatedAt($provider::class, scope: 'global'),
                );
            }
        }

        // Both admins and non-admins see their own user-scope configs.
        // Admins may want a personal override separate from the global default.
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        $userRows = ToolUserSetting::where('principal_id', $principalId)->get();
        foreach ($userRows as $row) {
            /** @var ToolUserSetting $row */
            $toolClass = (string) $row->tool_class;
            if (!$this->validator->isRegisteredProviderClass($toolClass)) {
                continue;
            }
            $decoded = $this->toolConfigService->getPrincipalSettings($toolClass, $principalId);
            $rows[] = $this->buildConfigResource(
                rowId: (int) $row->id,
                providerClass: $toolClass,
                scope: 'user',
                settings: $decoded,
                principalId: $principalId,
                createdAt: $row->created_at,
                updatedAt: $row->updated_at,
            );
        }

        return $rows;
    }

    /**
     * List the speech provider configs attached to a single group. The
     * caller must be a member of the group (or a global admin) to read
     * the group-scoped configs; non-members receive an empty list so
     * the existence-hide invariant matches the rest of the group
     * surface (a non-member doesn't know the group has STT configs).
     *
     * @return list<array<string, mixed>>
     */
    public function listGroupConfigs(int $userId, bool $isAdmin, int $groupId): array
    {
        $groupPrincipal = $this->principalService->principalForGroup($groupId);
        if ($groupPrincipal === null) {
            return [];
        }

        // Existence-hide: non-members (including non-member global
        // admins — they go through the admin overlay for cross-group
        // inspection) see an empty list so they can't tell whether the
        // group has any STT configs.
        $isMember = \Illuminate\Database\Capsule\Manager::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->exists();
        if (!$isMember && !$isAdmin) {
            return [];
        }

        $principalId = (int) $groupPrincipal->id;
        $rows = [];

        $groupRows = ToolUserSetting::where('principal_id', $principalId)->get();
        foreach ($groupRows as $row) {
            /** @var ToolUserSetting $row */
            $toolClass = (string) $row->tool_class;
            if (!$this->validator->isRegisteredProviderClass($toolClass)) {
                continue;
            }
            $rows[] = $this->buildConfigResource(
                rowId: (int) $row->id,
                providerClass: $toolClass,
                scope: 'group',
                settings: $this->toolConfigService->getPrincipalSettings($toolClass, $principalId),
                principalId: $principalId,
                createdAt: $row->created_at,
                updatedAt: $row->updated_at,
            );
        }

        return $rows;
    }

    /**
     * Return the provider-class picker schema: every registered
     * `SpeechToTextProviderInterface` with its declared
     * `#[ToolSetting]` attributes (label, type, default, required,
     * validation regex). Plugin-contributed providers are picked up
     * automatically — adding a new provider class is a server-side
     * addition that auto-appears in the SPA's create form.
     *
     * @return list<array<string, mixed>>
     */
    public function getSchema(): array
    {
        $rows = [];
        foreach ($this->registry->all() as $provider) {
            $class = $provider::class;
            $settings = $this->validator->collectSettingsSchema($class);
            if ($settings === []) {
                continue;
            }
            $rows[] = [
                'class'           => $class,
                'display_name'    => $provider->getDisplayName(),
                'settings_schema' => $settings,
            ];
        }
        return $rows;
    }

    /**
     * Upsert a config by (scope, provider_class, principal_id).
     *
     *  - `scope = 'global'` requires `$isAdmin` and writes to
     *    `tool_configurations`. Idempotent on `(tool_class)`.
     *  - `scope = 'user'` resolves the caller's user-principal and
     *    writes to `tool_user_settings`. Idempotent on
     *    `(tool_class, principal_id)`.
     *  - `scope = 'group'` requires `$groupId`, resolves the group's
     *    group-principal via {@see PrincipalService::ensureGroupPrincipal()},
     *    and writes to `tool_user_settings`. Auth: caller must be group
     *    admin (`GroupService::callerCanManage`) OR a global admin.
     *    Idempotent on `(tool_class, principal_id)`.
     *
     * @param array<string, mixed> $settings
     *
     * @throws SpeechProviderConfigException on schema mismatch / scope/auth failure / unknown provider class
     */
    public function upsertConfig(
        int $userId,
        bool $isAdmin,
        string $providerClass,
        string $scope,
        array $settings,
        ?int $groupId = null,
    ): array {
        $this->validator->assertRegisteredProviderClass($providerClass);

        if ($scope === 'global') {
            return $this->upsertGlobalConfig($providerClass, $isAdmin, $settings);
        }
        if ($scope === 'user') {
            return $this->upsertUserConfig($userId, $providerClass, $settings);
        }
        if ($scope === 'group') {
            if ($groupId === null || $groupId <= 0) {
                throw SpeechProviderConfigException::validation(
                    'scope "group" requires a positive "group_id".',
                );
            }
            if (!GroupService::callerCanManage($groupId, $userId, $isAdmin)) {
                throw SpeechProviderConfigException::forbidden(
                    'Only group owners, group admins, or global admins can write group speech provider configurations.',
                );
            }
            return $this->upsertGroupConfig($groupId, $providerClass, $settings);
        }

        throw SpeechProviderConfigException::validation(
            'scope must be either "global", "user", or "group".',
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function upsertGlobalConfig(string $providerClass, bool $isAdmin, array $settings): array
    {
        if (!$isAdmin) {
            throw SpeechProviderConfigException::forbidden(
                'Only admins can write global speech provider configurations.',
            );
        }
        $this->validator->assertSettingsAgainstSchema($providerClass, $settings);
        $this->toolConfigService->putGlobalSettings($providerClass, $settings);

        $rowId = $this->idResolver->globalConfigId($providerClass);
        if ($rowId === null) {
            throw SpeechProviderConfigException::notFound(
                "Global config row for {$providerClass} disappeared after write.",
            );
        }
        return $this->buildConfigResource(
            rowId: $rowId,
            providerClass: $providerClass,
            scope: 'global',
            settings: $this->toolConfigService->getGlobalSettings($providerClass),
            principalId: null,
            createdAt: $this->fetchCreatedAt($providerClass, scope: 'global'),
            updatedAt: $this->fetchUpdatedAt($providerClass, scope: 'global'),
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function upsertUserConfig(int $userId, string $providerClass, array $settings): array
    {
        $this->validator->assertSettingsAgainstSchema($providerClass, $settings);
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        $this->toolConfigService->putPrincipalSettings($providerClass, $principalId, $settings);

        $rowId = $this->idResolver->principalSettingsId($providerClass, $principalId);
        if ($rowId === null) {
            throw SpeechProviderConfigException::notFound(
                "User-scoped config row for {$providerClass} disappeared after write.",
            );
        }
        $row = ToolUserSetting::find($rowId);

        return $this->buildConfigResource(
            rowId: $rowId,
            providerClass: $providerClass,
            scope: 'user',
            settings: $this->toolConfigService->getPrincipalSettings($providerClass, $principalId),
            principalId: $principalId,
            createdAt: $row?->created_at,
            updatedAt: $row?->updated_at,
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function upsertGroupConfig(int $groupId, string $providerClass, array $settings): array
    {
        $this->validator->assertSettingsAgainstSchema($providerClass, $settings);
        $principalId = $this->principalService->ensureGroupPrincipal($groupId)->id;
        $this->toolConfigService->putPrincipalSettings($providerClass, $principalId, $settings);

        $rowId = $this->idResolver->principalSettingsId($providerClass, $principalId);
        if ($rowId === null) {
            throw SpeechProviderConfigException::notFound(
                "Group-scoped config row for {$providerClass} disappeared after write.",
            );
        }
        $row = ToolUserSetting::find($rowId);

        return $this->buildConfigResource(
            rowId: $rowId,
            providerClass: $providerClass,
            scope: 'group',
            settings: $this->toolConfigService->getPrincipalSettings($providerClass, $principalId),
            principalId: $principalId,
            createdAt: $row?->created_at,
            updatedAt: $row?->updated_at,
        );
    }

    /**
     * Resolve a config by row id (looking across both tables).
     *
     * @return array<string, mixed>|null
     */
    public function getConfig(int $userId, bool $isAdmin, int $id): ?array
    {
        $resource = $this->resolveConfigResource($id);
        if ($resource === null) {
            return null;
        }
        $userRow = ToolUserSetting::find($id);
        if ($userRow !== null && !$this->callerCanReadUserRow($userId, $isAdmin, (int) $userRow->principal_id)) {
            return null;
        }
        return $resource;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveConfigResource(int $id): ?array
    {
        $globalRow = ToolConfiguration::find($id);
        if ($globalRow !== null) {
            return $this->buildGlobalResource($globalRow);
        }
        $userRow = ToolUserSetting::find($id);
        return $userRow !== null ? $this->buildUserResource($userRow) : null;
    }

    private function callerCanReadUserRow(int $userId, bool $isAdmin, int $rowPrincipalId): bool
    {
        if ($isAdmin) {
            return true;
        }
        $callerPrincipalId = $this->principalService->ensureUserPrincipal($userId)->id;
        return $rowPrincipalId === $callerPrincipalId;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGlobalResource(ToolConfiguration $row): array
    {
        $providerClass = (string) $row->tool_class;
        return $this->buildConfigResource(
            rowId: (int) $row->id,
            providerClass: $providerClass,
            scope: 'global',
            settings: $this->toolConfigService->getGlobalSettings($providerClass),
            principalId: null,
            createdAt: $row->created_at,
            updatedAt: $row->updated_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUserResource(ToolUserSetting $row): array
    {
        $providerClass = (string) $row->tool_class;
        $principalId = (int) $row->principal_id;
        return $this->buildConfigResource(
            rowId: (int) $row->id,
            providerClass: $providerClass,
            scope: 'user',
            settings: $this->toolConfigService->getPrincipalSettings($providerClass, $principalId),
            principalId: $principalId,
            createdAt: $row->created_at,
            updatedAt: $row->updated_at,
        );
    }

    /**
     * Update an existing config by id. Resolves scope by which table
     * the id came from, then delegates to {@see upsertConfig()}.
     *
     * Group-scoped rows are gated by `GroupService::callerCanManage()`
     * against the row's underlying group id (no `group_id` is required
     * on the PUT body — the controller looks it up from the row).
     *
     * PUTs are partial: the request body only carries the fields the
     * operator changed. Required-but-omitted fields (most commonly
     * `api_key`, which the form omits when the operator intended to
     * keep the existing secret — see `SpeechProviderConfigForm.vue`'s
     * `buildSettingsToSend`) are filled from the existing storage
     * before the schema validator runs. The merged map is what
     * `upsertConfig()` writes — same value back for kept fields,
     * new value for changed fields, idempotent on disk.
     *
     * @param array<string, mixed> $settings
     */
    public function updateConfig(int $userId, bool $isAdmin, int $id, array $settings): array
    {
        $globalRow = ToolConfiguration::find($id);
        if ($globalRow !== null) {
            if (!$isAdmin) {
                throw SpeechProviderConfigException::forbidden(
                    'Only admins can update global speech provider configurations.',
                );
            }
            $existing = $this->toolConfigService->getGlobalSettings(
                (string) $globalRow->tool_class,
            );
            $merged = array_merge($existing, $settings);
            return $this->upsertConfig($userId, $isAdmin, (string) $globalRow->tool_class, 'global', $merged);
        }

        $userRow = ToolUserSetting::find($id);
        if ($userRow !== null) {
            $principalId = (int) $userRow->principal_id;
            $principal = Principal::find($principalId);
            $scope = ($principal !== null && $principal->type === Principal::TYPE_GROUP)
                ? 'group'
                : 'user';

            $existing = $this->toolConfigService->getPrincipalSettings(
                (string) $userRow->tool_class,
                $principalId,
            );
            $merged = array_merge($existing, $settings);

            if ($scope === 'group') {
                $groupId = (int) $principal->group_id;
                if (!GroupService::callerCanManage($groupId, $userId, $isAdmin)) {
                    throw SpeechProviderConfigException::forbidden(
                        'Only group owners, group admins, or global admins can update group speech provider configurations.',
                    );
                }
                return $this->upsertConfig(
                    userId: $userId,
                    isAdmin: $isAdmin,
                    providerClass: (string) $userRow->tool_class,
                    scope: 'group',
                    settings: $merged,
                    groupId: $groupId,
                );
            }

            $callerPrincipalId = $this->principalService->ensureUserPrincipal($userId)->id;
            if (!$isAdmin && $principalId !== $callerPrincipalId) {
                throw SpeechProviderConfigException::forbidden(
                    'You can only update your own speech provider configurations.',
                );
            }
            return $this->upsertConfig(
                userId: $userId,
                isAdmin: $isAdmin,
                providerClass: (string) $userRow->tool_class,
                scope: 'user',
                settings: $merged,
            );
        }

        throw SpeechProviderConfigException::notFound(
            "Speech provider configuration {$id} not found.",
        );
    }

    /**
     * Delete a config by id. Returns true on success, false when the
     * id didn't exist (or the caller isn't allowed to see/delete it).
     *
     * Group-scoped rows are gated by `GroupService::callerCanManage()`
     * against the row's underlying group id.
     */
    public function deleteConfig(int $userId, bool $isAdmin, int $id): bool
    {
        $globalRow = ToolConfiguration::find($id);
        if ($globalRow !== null) {
            if (!$isAdmin) {
                throw SpeechProviderConfigException::forbidden(
                    'Only admins can delete global speech provider configurations.',
                );
            }
            $this->toolConfigService->deleteGlobalSettings((string) $globalRow->tool_class);
            return true;
        }

        $userRow = ToolUserSetting::find($id);
        if ($userRow !== null) {
            $principalId = (int) $userRow->principal_id;
            $principal = Principal::find($principalId);

            if ($principal !== null && $principal->type === Principal::TYPE_GROUP) {
                $groupId = (int) $principal->group_id;
                if (!GroupService::callerCanManage($groupId, $userId, $isAdmin)) {
                    throw SpeechProviderConfigException::forbidden(
                        'Only group owners, group admins, or global admins can delete group speech provider configurations.',
                    );
                }
            } elseif (!$isAdmin) {
                $callerPrincipalId = $this->principalService->ensureUserPrincipal($userId)->id;
                if ($principalId !== $callerPrincipalId) {
                    throw SpeechProviderConfigException::forbidden(
                        'You can only delete your own speech provider configurations.',
                    );
                }
            }

            $this->toolConfigService->deletePrincipalSettings(
                (string) $userRow->tool_class,
                $principalId,
            );
            return true;
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildConfigResource(
        int $rowId,
        string $providerClass,
        string $scope,
        array $settings,
        ?int $principalId,
        mixed $createdAt,
        mixed $updatedAt,
    ): array {
        $masked = $this->toolConfigService->maskForApi($settings, $providerClass);
        $displayName = is_string($masked['display_name'] ?? null) ? $masked['display_name'] : '';
        if ($displayName === '') {
            $displayName = $this->providerDisplayName($providerClass);
        }

        return [
            'id'                     => $rowId,
            'provider_class'         => $providerClass,
            'provider_display_name'  => $this->providerDisplayName($providerClass),
            'scope'                  => $scope,
            'display_name'           => $displayName,
            'settings'               => $masked,
            'principal_id'           => $principalId,
            'created_at'             => $this->formatDateTime($createdAt),
            'updated_at'             => $this->formatDateTime($updatedAt),
        ];
    }

    private function providerDisplayName(string $class): string
    {
        foreach ($this->registry->all() as $provider) {
            if ($provider::class === $class) {
                return $provider->getDisplayName();
            }
        }
        if ($class === OpenAiCompatibleTranscriber::class) {
            return 'OpenAI Compatible';
        }
        return $class;
    }

    private function fetchCreatedAt(string $providerClass, string $scope): mixed
    {
        if ($scope === 'global') {
            return ToolConfiguration::where('tool_class', $providerClass)->value('created_at');
        }
        return null;
    }

    private function fetchUpdatedAt(string $providerClass, string $scope): mixed
    {
        if ($scope === 'global') {
            return ToolConfiguration::where('tool_class', $providerClass)->value('updated_at');
        }
        return null;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_string($value) && $value !== '' && ($ts = strtotime($value)) !== false
                => gmdate(DateTimeInterface::ATOM, $ts),
            default => null,
        };
    }
}
