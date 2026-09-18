<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Models\SpeechProviderConfiguration;
use Spora\Speech\SpeechToTextRegistry;

/**
 * Read-side facade for {@see SpeechProviderConfiguration}.
 *
 * Carved out of the umbrella that previously held both reads and
 * writes (PR #238) so it stays under the SonarCloud S1448
 * 20-method-per-class ceiling. Writes live on
 * {@see SpeechProviderConfigMutator}; the public
 * {@see SpeechProviderConfigServiceInterface} keeps the same surface so
 * the controller (and its tests) don't have to be updated.
 *
 * Read paths owned directly by this class:
 *   - {@see getSchema()}              — every registered STT class's
 *                                       `#[ToolSetting]` schema
 *   - {@see getConfigurationsForUser()} — union of principal-scoped +
 *                                         global configs visible to the
 *                                         caller
 *   - {@see getGlobalConfigurations()} — globals only
 *   - {@see getConfiguration()}       — single-row lookup with
 *                                         visibility scope
 *   - {@see findConfiguration()}      — unrestricted single-row lookup
 *   - {@see configResource()}         — wire-shape serializer
 *
 * Read paths delegated to {@see SpeechProviderConfigPersistence}:
 *   - {@see decodeSettings()}         — settings decryption round-trip
 *   - {@see maskForApi()}             — `***` mask for password fields
 *
 * Read paths delegated to {@see SpeechProviderConfigPreferences}:
 *   - {@see getDefaultConfiguration()}
 *   - {@see getPrincipalPreferredConfig()}
 *   - {@see resolvePreferredConfig()}
 *
 * Write paths (create / update / delete / setDefault / setPreferred
 * / unsetPreferred / resolveGroupPrincipal) forward to
 * {@see SpeechProviderConfigMutator} so the controller's API is
 * unchanged but the S1448 ceiling is satisfied. The controller's
 * preferred seam is the mutator; the service remains for legacy
 * callers that still speak the unified shape.
 */
final class SpeechProviderConfigService implements SpeechProviderConfigServiceInterface
{
    /**
     * Eloquent raw fragment that resolves to an always-false WHERE
     * clause. Used by visibility scopes when the caller has no
     * accessible principals — combined with the OR'd `is_global = true`
     * filter the result set collapses to empty. Two callers (one
     * inline, one via {@see applyVisibleScope()}) share this fragment;
     * promoted to a constant by SonarCloud S1192.
     */
    private const NO_MATCH = '1 = 0';

    private readonly SpeechProviderConfigValidator $validator;
    private readonly SpeechProviderConfigPersistence $persistence;
    private readonly SpeechProviderConfigPreferences $preferences;
    private readonly PrincipalResolver $principalResolver;
    private readonly SpeechProviderConfigMutator $mutator;

    public function __construct(
        SpeechProviderConfigValidator $validator,
        SpeechProviderConfigPersistence $persistence,
        SpeechProviderConfigPreferences $preferences,
        PrincipalService $principalService,
        ?PrincipalResolver $principalResolver = null,
        ?SpeechProviderConfigMutator $mutator = null,
    ) {
        $this->validator = $validator;
        $this->persistence = $persistence;
        $this->preferences = $preferences;
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
        $this->mutator = $mutator ?? new SpeechProviderConfigMutator(
            $persistence,
            $preferences,
            $principalService,
        );
    }

    /**
     * @return list<array{class: string, display_name: string, settings_schema: list<array<string, mixed>>}>
     */
    public function getSchema(SpeechToTextRegistry $registry): array
    {
        $rows = [];
        foreach ($registry->all() as $provider) {
            $class = $provider::class;
            $settings = $this->validator->collectSettingsSchema($class);
            if ($settings === []) {
                continue;
            }
            $rows[] = [
                'class' => $class,
                'display_name' => $provider->getDisplayName(),
                'settings_schema' => $settings,
            ];
        }
        return $rows;
    }

    /**
     * Union of every principal-scoped config the caller can see plus
     * all global configs. Mirrors {@see LLMConfigService::getConfigurationsForUser()}.
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigurationsForUser(int $userId): array
    {
        $principalIds = $this->principalResolver->visiblePrincipalIds($userId);

        $query = SpeechProviderConfiguration::where(static function ($q) use ($principalIds): void {
            if ($principalIds !== []) {
                $q->whereIn('principal_id', $principalIds);
            } else {
                $q->whereRaw(self::NO_MATCH);
            }
            $q->orWhere('is_global', true);
        });

        return $this->mapToResources($query->get());
    }

    /**
     * Configs valid for one agent's principal scope, plus every global
     * config. Mirrors {@see LLMConfigService::getConfigurationsForAgent()}.
     *
     * Behaviour:
     *  - Agent row missing → `[]`.
     *  - Otherwise → `principal_id = agent.principal_id` ∪ `is_global = true`.
     *
     * The scope is the agent's own principal, NOT the caller's visible
     * principals — a user-owned agent's dropdown stays scoped to that
     * user's user-principal (no group-owned configs leak in), and a
     * group-owned agent's dropdown stays scoped to that group's
     * principal (no caller user-scoped configs leak in). The SPA's
     * "Voice not configured" empty-state CTA renders correctly when
     * the agent's principal has no matching config.
     *
     * Visibility is the controller's concern: callers must pre-check
     * that the user is allowed to view the agent (or is an admin)
     * before invoking this method.
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigurationsForAgent(int $agentId): array
    {
        $agent = Agent::find($agentId);
        if ($agent === null) {
            return [];
        }

        $principalId = (int) $agent->principal_id;

        $query = SpeechProviderConfiguration::where(static function ($q) use ($principalId): void {
            $q->where('principal_id', $principalId)
                ->orWhere('is_global', true);
        });

        return $this->mapToResources($query->get());
    }

    /**
     * Configs valid for ONE group (the group identified by
     * `groups.id`), plus every global config. Used by the SPA's group
     * settings page so configs owned by other groups the caller
     * belongs to don't leak into the single-group dropdown.
     *
     * Mirrors {@see LLMConfigService::getConfigurationsForAgent()}
     * in pattern: caller-side dispatch keyed on the group's principal
     * id, with the same existence-hide fallback as the per-agent path.
     *
     * Visibility gate:
     *  - Group missing the principal row → `[]` (data corruption).
     *  - Caller is admin → returns the group's configs + globals.
     *  - Caller is a member (their visible principals include the
     *    group's principal) → returns the group's configs + globals.
     *  - Otherwise → `[]` (existence-hide for non-members).
     *
     * Used by `GET /api/v1/speech/provider-configs?group_id=N` so the
     * caller-scoped fallback (`getConfigurationsForUser()`) doesn't
     * leak every group the caller belongs to into the single-group
     * page response.
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigurationsForGroup(int $groupId, int $userId, bool $isAdmin): array
    {
        $principalId = (int) Principal::where('type', Principal::TYPE_GROUP)
            ->where('group_id', $groupId)
            ->value('id');

        if ($principalId <= 0) {
            return [];
        }

        if (!$isAdmin) {
            $visiblePrincipalIds = $this->principalResolver->visiblePrincipalIds($userId);
            if (!in_array($principalId, $visiblePrincipalIds, true)) {
                return [];
            }
        }

        $query = SpeechProviderConfiguration::where(static function ($q) use ($principalId): void {
            $q->where('principal_id', $principalId)
                ->orWhere('is_global', true);
        });

        return $this->mapToResources($query->get());
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, SpeechProviderConfiguration> $configs
     * @return list<array<string, mixed>>
     */
    private function mapToResources(iterable $configs): array
    {
        $rows = [];
        foreach ($configs as $config) {
            $rows[] = $this->persistence->configResource($config);
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGlobalConfigurations(): array
    {
        return $this->mapToResources(
            SpeechProviderConfiguration::where('is_global', true)
                ->orderBy('display_name')
                ->get(),
        );
    }

    public function getConfiguration(int $configId, int $userId, bool $isAdmin = false): ?SpeechProviderConfiguration
    {
        $query = SpeechProviderConfiguration::where('id', $configId);
        if (!$isAdmin) {
            $this->applyVisibleScope($query, $userId);
        }
        return $query->first();
    }

    private function applyVisibleScope(\Illuminate\Database\Eloquent\Builder $query, int $userId): void
    {
        $principalIds = $this->principalResolver->visiblePrincipalIds($userId);
        $query->where(static function ($q) use ($principalIds): void {
            if ($principalIds === []) {
                $q->whereRaw(self::NO_MATCH);
            } else {
                $q->whereIn('principal_id', $principalIds);
            }
            $q->orWhere('is_global', true);
        });
    }

    public function createConfiguration(int $userId, array $data, bool $isAdmin): SpeechProviderConfiguration
    {
        return $this->mutator->createConfiguration($userId, $data, $isAdmin);
    }

    public function resolveGroupPrincipal(int $groupId, int $callerUserId, bool $isAdmin): ?int
    {
        return $this->mutator->resolveGroupPrincipal($groupId, $callerUserId, $isAdmin);
    }

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): SpeechProviderConfiguration
    {
        return $this->mutator->updateConfiguration($configId, $userId, $data, $isAdmin);
    }

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool
    {
        return $this->mutator->deleteConfiguration($configId, $userId, $isAdmin);
    }

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): SpeechProviderConfiguration
    {
        return $this->mutator->setDefaultConfiguration($configId, $userId, $isAdmin);
    }

    public function getDefaultConfiguration(): ?SpeechProviderConfiguration
    {
        return $this->preferences->getDefaultConfiguration();
    }

    public function getPrincipalPreferredConfig(int $principalId): ?SpeechProviderConfiguration
    {
        return $this->preferences->getPrincipalPreferredConfig($principalId);
    }

    public function setPrincipalPreferredConfig(int $principalId, int $configId, int $callerUserId): bool
    {
        return $this->preferences->setPrincipalPreferredConfig($principalId, $configId, $callerUserId);
    }

    public function unsetPrincipalPreferredConfig(int $principalId): void
    {
        $this->preferences->unsetPrincipalPreferredConfig($principalId);
    }

    public function resolvePreferredConfig(int $userId, bool $isAdmin, ?int $groupId = null, string $scope = 'user'): ?SpeechProviderConfiguration
    {
        return $this->preferences->resolvePreferredConfig($userId, $isAdmin, $groupId, $scope);
    }

    /**
     * @return array<string, mixed>
     */
    public function configResource(SpeechProviderConfiguration $config): array
    {
        return $this->persistence->configResource($config);
    }
}
