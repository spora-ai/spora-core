<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\LLMDriverConfiguration;

/**
 * Service interface for LLM driver configuration CRUD.
 */
interface LLMConfigServiceInterface
{
    /**
     * @return list<array>
     */
    public function getDrivers(): array;

    public function getConfigurationsForUser(int $userId): array;

    public function getGlobalConfigurations(): array;

    public function getConfiguration(int $configId, int $userId): ?LLMDriverConfiguration;

    public function createConfiguration(int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration;

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration;

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool;

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): ?LLMDriverConfiguration;

    public function getDefaultConfiguration(int $userId): ?LLMDriverConfiguration;

    /**
     * Find a configuration by ID (for authorization checks).
     */
    public function findConfiguration(int $configId): ?LLMDriverConfiguration;

    /**
     * @return array<string, mixed>
     */
    public function configResource(LLMDriverConfiguration $config): array;

    /**
     * @return array<string, mixed>
     */
    public function decodeSettings(string $driverClass, ?string $raw): array;

    /**
     * Alias for decodeSettings for backwards compatibility.
     *
     * @return array<string, mixed>
     */
    public function decryptSettings(string $driverClass, ?string $raw): array;

    /**
     * @param array<string, mixed> $settings
     * @param list<array> $schema
     * @return array<string, mixed>
     */
    public function maskForApi(array $settings, array $schema): array;
}
