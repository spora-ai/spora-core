<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\SpeechProviderConfiguration;
use Spora\Speech\SpeechToTextRegistry;

/**
 * Service interface for speech-to-text provider configuration CRUD.
 *
 * Mirrors {@see LLMConfigServiceInterface} — the same operations,
 * same wire shapes; the implementation
 * ({@see SpeechProviderConfigService}) is the speech-side single
 * source of truth.
 */
interface SpeechProviderConfigServiceInterface
{
    /**
     * @return list<array{class: string, display_name: string, settings_schema: list<array<string, mixed>>}>
     */
    public function getSchema(SpeechToTextRegistry $registry): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getConfigurationsForUser(int $userId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getGlobalConfigurations(): array;

    public function getConfiguration(int $configId, int $userId, bool $isAdmin = false): ?SpeechProviderConfiguration;

    public function createConfiguration(int $userId, array $data, bool $isAdmin): ?SpeechProviderConfiguration;

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): ?SpeechProviderConfiguration;

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool;

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): ?SpeechProviderConfiguration;

    public function getDefaultConfiguration(): ?SpeechProviderConfiguration;

    public function getPrincipalPreferredConfig(int $principalId): ?SpeechProviderConfiguration;

    public function setPrincipalPreferredConfig(int $principalId, int $configId, int $callerUserId): bool;

    public function unsetPrincipalPreferredConfig(int $principalId): void;

    public function resolvePreferredConfig(int $userId, bool $isAdmin, ?int $groupId = null, string $scope = 'user'): ?SpeechProviderConfiguration;

    public function findConfiguration(int $configId): ?SpeechProviderConfiguration;

    /**
     * @return array<string, mixed>
     */
    public function configResource(SpeechProviderConfiguration $config): array;

    /**
     * @return array<string, mixed>
     */
    public function decodeSettings(string $providerClass, ?string $raw): array;

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function maskForApi(string $providerClass, array $settings): array;
}
