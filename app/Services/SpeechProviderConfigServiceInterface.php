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
     * Configs valid for one agent's principal scope, plus every global
     * config. Used by the SPA's agent-settings page so that a user-owned
     * agent's dropdown doesn't show configs owned by groups the caller
     * happens to belong to (and vice versa).
     *
     * Visibility is the controller's concern; callers must pre-check
     * that the user is allowed to view the agent (or is an admin)
     * before invoking this method.
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigurationsForAgent(int $agentId): array;

    /**
     * Configs valid for ONE group (the group identified by
     * `groups.id`), plus every global config. Used by the SPA's group
     * settings page so configs owned by other groups the caller belongs
     * to don't leak into the single-group dropdown / list view.
     *
     * Visibility gate: non-admin callers must be a member of the
     * group; non-members get `[]` (existence-hide). Mirrors the gate on
     * {@see LLMConfigService::index()} for the parallel LLM resource.
     *
     * @return list<array<string, mixed>>
     */
    public function getConfigurationsForGroup(int $groupId, int $userId, bool $isAdmin): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getGlobalConfigurations(): array;

    public function getConfiguration(int $configId, int $userId, bool $isAdmin = false): ?SpeechProviderConfiguration;

    public function createConfiguration(int $userId, array $data, bool $isAdmin): SpeechProviderConfiguration;

    /**
     * Resolve a `groups.id` (from the SPA's wire shape) to the matching
     * `principals.id` so the controller can target a group-scope write.
     * Returns `null` when the caller is not authorised to manage the group.
     */
    public function resolveGroupPrincipal(int $groupId, int $callerUserId, bool $isAdmin): ?int;

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): SpeechProviderConfiguration;

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool;

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): SpeechProviderConfiguration;

    public function getDefaultConfiguration(): ?SpeechProviderConfiguration;

    public function getPrincipalPreferredConfig(int $principalId): ?SpeechProviderConfiguration;

    public function setPrincipalPreferredConfig(int $principalId, int $configId, int $callerUserId): bool;

    public function unsetPrincipalPreferredConfig(int $principalId): void;

    public function resolvePreferredConfig(int $userId, bool $isAdmin, ?int $groupId = null, string $scope = 'user'): ?SpeechProviderConfiguration;

    /**
     * @return array<string, mixed>
     */
    public function configResource(SpeechProviderConfiguration $config): array;
}
