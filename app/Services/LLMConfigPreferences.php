<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\PrincipalPreference;

/**
 * Default-resolution and principal-preference logic for LLM configurations.
 *
 * Owns the three-tier fallback that decides which LLMDriverConfiguration
 * an agent should use (agent-specific → principal preferred → global default),
 * the principal-preference CRUD that backs tier 2, and the admin-only
 * "set the global default" flow that backs tier 3.
 *
 * The preferences path crosses principal/agent boundaries, so this is the
 * highest-risk collaborator in the split: tests must keep the
 * tier-1/2/3 resolution working end-to-end.
 *
 * Migration 0067 renamed `user_preferences` to `principal_preferences` and
 * re-keyed the FK from `users` to `principals`. Methods keyed on the old
 * `userId` axis now pass through PrincipalService so the same identity
 * guarantee applies (callers may pass their own user-principal id).
 */
final class LLMConfigPreferences
{
    private readonly PrincipalService $principalService;

    public function __construct(
        ?PrincipalService $principalService = null,
    ) {
        $this->principalService = $principalService ?? new PrincipalService(new PrincipalResolver());
    }

    public function setDefaultConfiguration(int $configId, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = $this->loadDefaultableConfiguration($configId, $isAdmin);
        if ($config === null) {
            return null;
        }

        LLMDriverConfiguration::where('is_global', true)->where('is_default', true)->update(['is_default' => false]);

        $config->is_default = true;
        $config->save();

        return $config;
    }

    /**
     * Returns the default LLMDriverConfiguration (is_default = true).
     */
    public function getDefaultConfiguration(int $userId): ?LLMDriverConfiguration
    {
        return $this->getPrincipalPreferredConfig($this->principalService->ensureUserPrincipal($userId)->id);
    }

    /**
     * Resolves the effective LLMDriverConfiguration for an agent using three-tier fallback.
     *
     * Tier 1: Agent-specific config     (agent.llm_driver_config_id)
     * Tier 2: Principal preferred config (principal_preferences.preferred_llm_config_id)
     * Tier 3: Global default           (is_global=true, is_default=true)
     */
    public function getEffectiveConfigForAgent(Agent $agent): ?LLMDriverConfiguration
    {
        if ($agent->llm_driver_config_id !== null) {
            $config = LLMDriverConfiguration::find($agent->llm_driver_config_id);
            if ($config !== null) {
                return $config;
            }
        }

        $config = $this->getPrincipalPreferredConfig($agent->principal_id);
        if ($config !== null) {
            return $config;
        }

        return LLMDriverConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->first();
    }

    public function getPrincipalPreferredConfig(int $principalId): ?LLMDriverConfiguration
    {
        $preference = PrincipalPreference::where('principal_id', $principalId)->first();
        if ($preference === null || $preference->preferred_llm_config_id === null) {
            return null;
        }

        return LLMDriverConfiguration::find($preference->preferred_llm_config_id);
    }

    /**
     * Persist a principal's preferred LLM config. The target config must
     * either be global (shared with everyone) or belong to the same
     * principal — cross-principal pointers would surface the wrong
     * config when the agent runs.
     */
    public function setPrincipalPreferredConfig(int $principalId, int $configId, int $callerUserId): bool
    {
        if (!$this->isConfigEligibleForPrincipal($configId, $principalId, $callerUserId)) {
            return false;
        }

        PrincipalPreference::firstOrCreate(['principal_id' => $principalId])
            ->fill(['preferred_llm_config_id' => $configId])
            ->save();

        return true;
    }

    private function isConfigEligibleForPrincipal(int $configId, int $principalId, int $callerUserId): bool
    {
        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return false;
        }

        // Auth gate: a caller cannot write a preference for a principal
        // they don't act as. Admin short-circuits; otherwise the caller
        // must own the principal (own user-principal or owner of the
        // underlying group).
        if (!$this->principalService->callerControlsPrincipal($callerUserId, $principalId)) {
            return false;
        }

        return $config->is_global || (int) $config->principal_id === $principalId;
    }

    public function unsetPrincipalPreferredConfig(int $principalId): void
    {
        PrincipalPreference::where('principal_id', $principalId)->delete();
    }

    /**
     * Backwards-compatible shim: legacy callers indexed LLM preferences
     * by `userId`. Resolves the caller's user-principal and delegates.
     * Moved from {@see LLMConfigService} so the main service stays under
     * the SonarCloud 20-method-per-class ceiling (S1448).
     */
    public function getUserPreferredConfig(int $userId): ?LLMDriverConfiguration
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        return $this->getPrincipalPreferredConfig($principalId);
    }

    /**
     * Backwards-compatible shim — see {@see self::getUserPreferredConfig()}.
     */
    public function setUserPreferredConfig(int $userId, int $configId): bool
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        return $this->setPrincipalPreferredConfig($principalId, $configId, $userId);
    }

    /**
     * Backwards-compatible shim — see {@see self::getUserPreferredConfig()}.
     */
    public function unsetUserPreferredConfig(int $userId): void
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        $this->unsetPrincipalPreferredConfig($principalId);
    }

    private function loadDefaultableConfiguration(int $configId, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = LLMDriverConfiguration::find($configId);
        $eligible = $config !== null && $config->is_global && $isAdmin;

        if (!$eligible) {
            return null;
        }

        return $config;
    }
}
