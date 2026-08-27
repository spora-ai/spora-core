<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Public surface of ToolConfigService.
 *
 * The interface exists so that final ToolConfigService can be mocked in
 * tests (Mockery cannot mock final classes). All real callers depend on
 * the implementation via this interface; tests can substitute a mock
 * implementation that satisfies the same contract.
 *
 * Migration 0067 cut the wire format over to `principal_id`. The
 * legacy `(string, int $userId)` signatures remain as thin adapters so
 * `ToolController` (whose refactor is a separate task) doesn't break;
 * new callers should use the principal-typed methods directly.
 */
interface ToolConfigServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getGlobalSettings(string $toolClass): array;

    public function putGlobalSettings(string $toolClass, array $settings): void;

    public function deleteGlobalSettings(string $toolClass): void;

    /**
     * @return array<string, mixed>
     */
    public function getPrincipalSettings(string $toolClass, int $principalId): array;

    /**
     * @return array<string, mixed>
     */
    public function putPrincipalSettings(string $toolClass, int $principalId, array $settings): array;

    public function deletePrincipalSettings(string $toolClass, int $principalId): void;

    public function putAgentOverride(string $toolClass, int $agentId, array $settings): void;

    public function deleteAgentOverride(string $toolClass, int $agentId): void;

    /**
     * @return array<string, mixed>
     */
    public function getRawAgentOverride(string $toolClass, int $agentId): array;

    /**
     * @return array<string, mixed>
     */
    public function getEffectiveSettings(string $toolClass, int $agentId, ?int $userId = null, ?PrincipalContext $context = null): array;

    /**
     * @return array<string, array{value: mixed, source: 'global'|'principal'|'agent'|'default'}>
     */
    public function getEffectiveSettingsWithSource(string $toolClass, int $agentId, ?int $userId = null, ?PrincipalContext $context = null): array;

    /**
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function maskForApi(array $settings, string $toolClass): array;

    /**
     * @param array<string, mixed> $settings
     */
    public function encryptSettings(string $toolClass, array $settings): string;

    /**
     * @return array<string, mixed>
     */
    public function decryptSettings(string $storageString): array;

    public function resolveToolClass(string $toolName): ?string;

    /**
     * @return list<string>
     */
    public function getRegisteredToolClasses(): array;

    /**
     * @return array<string, mixed>
     */
    public function getSchemaDefaults(string $toolClass): array;

    /**
     * @param  array<string, mixed> $effectiveSettings
     * @return list<string>
     */
    public function getMissingRequiredSettings(string $toolClass, array $effectiveSettings): array;

    /**
     * @return array<string, array{label: string, value: mixed}>
     */
    public function getLlmToolSettings(string $toolClass, int $agentId, ?int $userId = null, ?PrincipalContext $context = null): array;
}
