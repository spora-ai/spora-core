<?php

declare(strict_types=1);

namespace Spora\Services;

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
                $q->whereRaw('1 = 0');
            }
            $q->orWhere('is_global', true);
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
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('principal_id', $principalIds);
            }
            $q->orWhere('is_global', true);
        });
    }

    public function findConfiguration(int $configId): ?SpeechProviderConfiguration
    {
        return SpeechProviderConfiguration::find($configId);
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

    /**
     * @return array<string, mixed>
     */
    public function decodeSettings(string $providerClass, ?string $raw): array
    {
        return $this->persistence->decodeSettings($providerClass, $raw);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function maskForApi(string $providerClass, array $settings): array
    {
        return $this->persistence->maskForApi($providerClass, $settings);
    }
}
