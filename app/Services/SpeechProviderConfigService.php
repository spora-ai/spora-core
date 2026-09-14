<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\SpeechProviderConfiguration;
use Spora\Speech\SpeechToTextRegistry;

/**
 * Service for managing speech-to-text provider configurations.
 *
 * Mirrors {@see LLMConfigService}: thin facade that delegates CRUD +
 * default-toggle + principal-preference work to four focused
 * collaborators:
 *   - {@see SpeechProviderConfigValidator} for class + schema checks
 *   - {@see SpeechProviderConfigPersistence} for CRUD with per-field
 *     encryption
 *   - {@see SpeechProviderConfigPreferences} for the principal-id
 *     preference writer / reader and the "set the global default"
 *     flow that backs the tier-4 cascade in
 *     {@see \Spora\Speech\SpeechToTextRegistry::resolveEffectiveClassWithSource()}
 *   - {@see SpeechToTextRegistry} (read-only) for class discovery +
 *     effective-class resolution metadata
 *
 * Migration 0085 introduced `speech_provider_configurations` (the
 * speech mirror of `llm_driver_configurations`) and migration 0088
 * swapped `preferred_speech_provider_class` for an FK — both are
 * unified into this surface.
 *
 * Singleton scope is request-lifetime (the DI container builds a
 * fresh instance per resolve); the underlying SecurityManager /
 * registry are the shared collaborators.
 */
final class SpeechProviderConfigService implements SpeechProviderConfigServiceInterface
{
    private readonly SpeechProviderConfigValidator $validator;
    private readonly SpeechProviderConfigPersistence $persistence;
    private readonly SpeechProviderConfigPreferences $preferences;
    private readonly PrincipalService $principalService;
    private readonly PrincipalResolver $principalResolver;

    public function __construct(
        SpeechProviderConfigValidator $validator,
        SpeechProviderConfigPersistence $persistence,
        SpeechProviderConfigPreferences $preferences,
        PrincipalService $principalService,
        ?PrincipalResolver $principalResolver = null,
    ) {
        $this->validator = $validator;
        $this->persistence = $persistence;
        $this->preferences = $preferences;
        $this->principalService = $principalService;
        $this->principalResolver = $principalResolver ?? new PrincipalResolver();
    }

    // ---------------------------------------------------------------------
    // Class discovery (delegated to the registry)
    // ---------------------------------------------------------------------

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

    // ---------------------------------------------------------------------
    // Listing / lookup (read paths)
    // ---------------------------------------------------------------------

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

        return $query->get()
            ->map(fn(SpeechProviderConfiguration $config): array => $this->persistence->configResource($config))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGlobalConfigurations(): array
    {
        return SpeechProviderConfiguration::where('is_global', true)
            ->orderBy('display_name')
            ->get()
            ->map(fn(SpeechProviderConfiguration $config): array => $this->persistence->configResource($config))
            ->all();
    }

    public function getConfiguration(int $configId, int $userId, bool $isAdmin = false): ?SpeechProviderConfiguration
    {
        $query = SpeechProviderConfiguration::where('id', $configId);
        if (!$isAdmin) {
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
        return $query->first();
    }

    public function findConfiguration(int $configId): ?SpeechProviderConfiguration
    {
        return SpeechProviderConfiguration::find($configId);
    }

    // ---------------------------------------------------------------------
    // Configuration mutations (delegated to Persistence)
    // ---------------------------------------------------------------------

    public function createConfiguration(int $userId, array $data, bool $isAdmin): ?SpeechProviderConfiguration
    {
        $principalId = isset($data['principal_id']) && is_int($data['principal_id'])
            ? $data['principal_id']
            : $this->principalService->ensureUserPrincipal($userId)->id;

        unset($data['principal_id']);

        return $this->persistence->createConfiguration($principalId, $userId, $data, $isAdmin);
    }

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): ?SpeechProviderConfiguration
    {
        return $this->persistence->updateConfiguration($configId, $userId, $data, $isAdmin);
    }

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool
    {
        return $this->persistence->deleteConfiguration($configId, $userId, $isAdmin);
    }

    // ---------------------------------------------------------------------
    // Default + preference resolution (delegated to Preferences)
    // ---------------------------------------------------------------------

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): ?SpeechProviderConfiguration
    {
        unset($userId);
        return $this->preferences->setDefaultConfiguration($configId, $isAdmin);
    }

    public function getDefaultConfiguration(int $userId): ?SpeechProviderConfiguration
    {
        return $this->preferences->getDefaultConfiguration($userId);
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

    // ---------------------------------------------------------------------
    // Resource DTO (delegated to Persistence)
    // ---------------------------------------------------------------------

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
