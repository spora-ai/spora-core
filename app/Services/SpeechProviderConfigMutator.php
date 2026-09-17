<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\SpeechProviderConfiguration;

/**
 * Write-side companion for {@see SpeechProviderConfigService}.
 *
 * Splits the CRUD + default-toggle + preference-write methods out of
 * the read-side service so the latter stays under the SonarCloud
 * S1448 20-method-per-class ceiling. The public API stays stable
 * because every method here is a verbatim lift-and-shift from the
 * previous {@see SpeechProviderConfigService} body (PR #238) — the
 * controller talks to it through the same
 * {@see SpeechProviderConfigServiceInterface} so callers don't have
 * to be updated.
 *
 * Read paths (list / schema / getConfiguration / configResource) stay
 * on the service; mutating paths (create / update / delete /
 * setDefault / setPreferred) move here. Preferences writes are split:
 * the mutator hands off set/unset principal preference and the
 * "set global default" flows so the dedicated
 * {@see SpeechProviderConfigPreferences} collaborator keeps its
 * responsibility for default + principal-preference resolution.
 */
final class SpeechProviderConfigMutator implements SpeechProviderConfigMutatorInterface
{
    private readonly SpeechProviderConfigPersistence $persistence;
    private readonly SpeechProviderConfigPreferences $preferences;
    private readonly PrincipalService $principalService;

    public function __construct(
        SpeechProviderConfigPersistence $persistence,
        SpeechProviderConfigPreferences $preferences,
        PrincipalService $principalService,
    ) {
        $this->persistence = $persistence;
        $this->preferences = $preferences;
        $this->principalService = $principalService;
    }

    public function createConfiguration(int $userId, array $data, bool $isAdmin): SpeechProviderConfiguration
    {
        // Only default the principal to the caller's user-principal when
        // the request is principal-scoped. Global rows always null out
        // `principal_id` in the persistence layer, so materialising a
        // user-principal just to throw it away is a wasted DB round-trip
        // (per the 2026-09-17 light review).
        $principalId = isset($data['principal_id']) && is_int($data['principal_id'])
            ? $data['principal_id']
            : (empty($data['is_global'])
                ? $this->principalService->ensureUserPrincipal($userId)->id
                : null);

        unset($data['principal_id']);

        return $this->persistence->createConfiguration($principalId, $userId, $data, $isAdmin);
    }

    public function resolveGroupPrincipal(int $groupId, int $callerUserId, bool $isAdmin): ?int
    {
        if (!GroupService::callerCanManage($groupId, $callerUserId, $isAdmin)) {
            return null;
        }

        return (int) $this->principalService->ensureGroupPrincipal($groupId)->id;
    }

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): SpeechProviderConfiguration
    {
        return $this->persistence->updateConfiguration($configId, $userId, $data, $isAdmin);
    }

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool
    {
        return $this->persistence->deleteConfiguration($configId, $userId, $isAdmin);
    }

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): SpeechProviderConfiguration
    {
        unset($userId);
        return $this->preferences->setDefaultConfiguration($configId, $isAdmin);
    }
}
