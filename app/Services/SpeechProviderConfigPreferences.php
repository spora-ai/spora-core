<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Http\Exceptions\SpeechProviderConfigException;
use Spora\Models\PrincipalPreference;
use Spora\Models\SpeechProviderConfiguration;
use Throwable;

/**
 * Default-resolution + principal-preference logic for
 * {@see SpeechProviderConfiguration}.
 *
 * Models the speech side of:
 *   - the four-tier effective-class cascade in
 *     {@see \Spora\Speech\SpeechToTextRegistry::resolveEffectiveClassWithSource()}
 *     (the public read API goes through the registry; this class is
 *     the underlying writer)
 *   - the "set the global default" admin flow that backs tier 4
 *   - the principal-preference writer that backs tiers 2 and 3
 *
 * Concurrency: `setDefaultConfiguration` mirrors the LLM-side fix
 * (PR #238 issue #5) — wrapped in a transaction with
 * `lockForUpdate` on the prior global-default row so concurrent
 * admin promotions cannot land two defaults or zero defaults.
 */
final class SpeechProviderConfigPreferences
{
    public function __construct(
        private readonly PrincipalService $principalService,
    ) {}

    /**
     * @throws SpeechProviderConfigException 404 when the config id
     *         doesn't exist; 403 when the row exists but isn't
     *         global or the caller isn't admin.
     * @throws Throwable on transaction failure (rethrown); the inner
     *         `save()` calls bubble up model exceptions as-is.
     */
    public function setDefaultConfiguration(int $configId, bool $isAdmin): SpeechProviderConfiguration
    {
        return Capsule::connection()->transaction(
            function () use ($configId, $isAdmin): SpeechProviderConfiguration {
                $config = SpeechProviderConfiguration::where('id', $configId)
                    ->lockForUpdate()
                    ->first();
                if ($config === null) {
                    throw SpeechProviderConfigException::notFound(
                        "Speech provider configuration {$configId} not found.",
                    );
                }
                if (!$isAdmin || !(bool) $config->is_global) {
                    throw SpeechProviderConfigException::forbidden(
                        'Only admins can set a global speech provider configuration as default.',
                    );
                }

                $priorDefault = SpeechProviderConfiguration::where('is_global', true)
                    ->where('is_default', true)
                    ->where('id', '!=', $config->id)
                    ->lockForUpdate()
                    ->first();
                if ($priorDefault !== null) {
                    $priorDefault->is_default = false;
                    $priorDefault->save();
                }

                $config->is_default = true;
                $config->save();

                return $config;
            },
        );
    }

    public function getDefaultConfiguration(): ?SpeechProviderConfiguration
    {
        return SpeechProviderConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->first();
    }

    public function getPrincipalPreferredConfig(int $principalId): ?SpeechProviderConfiguration
    {
        $preference = PrincipalPreference::where('principal_id', $principalId)->first();
        if ($preference === null || $preference->preferred_speech_config_id === null) {
            return null;
        }
        return SpeechProviderConfiguration::find($preference->preferred_speech_config_id);
    }

    public function setPrincipalPreferredConfig(int $principalId, int $configId, int $callerUserId): bool
    {
        if (!$this->isConfigEligibleForPrincipal($configId, $principalId, $callerUserId)) {
            return false;
        }

        PrincipalPreference::firstOrCreate(['principal_id' => $principalId])
            ->fill(['preferred_speech_config_id' => $configId])
            ->save();

        return true;
    }

    public function unsetPrincipalPreferredConfig(int $principalId): void
    {
        PrincipalPreference::where('principal_id', $principalId)
            ->update(['preferred_speech_config_id' => null]);
    }

    /**
     * Resolve the principal's preferred STT config, validating the
     * pointed-at row still exists (a stale pointer falls through
     * to `null` so the cascade treats it as unset).
     *
     * For `scope='user'` reads the caller's own user-principal;
     * for `scope='group'` reads the named group's group-principal
     * (existence-hide for non-members — see controller).
     */
    public function resolvePreferredConfig(int $userId, bool $isAdmin, ?int $groupId = null, string $scope = 'user'): ?SpeechProviderConfiguration
    {
        if ($scope === 'group') {
            return $this->resolveGroupPreferredConfig($userId, $isAdmin, $groupId);
        }

        $principalId = (int) $this->principalService->ensureUserPrincipal($userId)->id;
        return $this->getPrincipalPreferredConfig($principalId);
    }

    private function resolveGroupPreferredConfig(int $userId, bool $isAdmin, ?int $groupId): ?SpeechProviderConfiguration
    {
        if ($groupId === null) {
            return null;
        }
        $principalId = $this->resolveGroupPrincipalId($userId, $isAdmin, $groupId);
        if ($principalId === null) {
            return null;
        }
        return $this->getPrincipalPreferredConfig($principalId);
    }

    /**
     * Resolve the group-principal id, enforcing the existence-hide rule
     * (non-admin non-members fall through to `null`). Extracted from
     * {@see resolveGroupPreferredConfig()} to drop the S1142 return count.
     */
    private function resolveGroupPrincipalId(int $userId, bool $isAdmin, int $groupId): ?int
    {
        $groupPrincipal = $this->principalService->principalForGroup($groupId);
        if ($groupPrincipal === null) {
            return null;
        }
        if (!$isAdmin && !$this->isGroupMember($userId, $groupId)) {
            return null;
        }
        return (int) $groupPrincipal->id;
    }

    private function isGroupMember(int $userId, int $groupId): bool
    {
        return Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function isConfigEligibleForPrincipal(int $configId, int $principalId, int $callerUserId): bool
    {
        $config = SpeechProviderConfiguration::find($configId);
        if ($config === null) {
            return false;
        }

        if (!$this->principalService->callerControlsPrincipal($callerUserId, $principalId)) {
            return false;
        }

        return (bool) $config->is_global || (int) $config->principal_id === $principalId;
    }
}
