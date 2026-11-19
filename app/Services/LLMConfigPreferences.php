<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Principal;
use Spora\Models\PrincipalPreference;

/**
 * Default-resolution and principal-preference logic for LLM configurations.
 *
 * Owns the four-tier fallback that decides which LLMDriverConfiguration
 * an agent should use (agent-specific → principal preferred → principal
 * default → global default), the principal-preference CRUD that backs
 * tier 2, and the admin-only "set the global default" flow that backs
 * tier 4.
 *
 * The preferences path crosses principal/agent boundaries, so this is the
 * highest-risk collaborator in the split: tests must keep the
 * tier-1/2/3/4 resolution working end-to-end.
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
        return Capsule::connection()->transaction(
            function () use ($configId, $isAdmin): ?LLMDriverConfiguration {
                $config = $this->loadDefaultableConfigurationForUpdate($configId, $isAdmin);
                if ($config === null) {
                    return null;
                }

                $priorDefault = LLMDriverConfiguration::where('is_global', true)
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

    /**
     * The caller's preferred configuration — their user-principal's
     * preference, not the global default.
     */
    public function getDefaultConfiguration(int $userId): ?LLMDriverConfiguration
    {
        return $this->getPrincipalPreferredConfig($this->principalService->ensureUserPrincipal($userId)->id);
    }

    /**
     * The effective configuration for an agent, and the tier that produced it.
     *
     * Four-tier fallback:
     *
     * Tier 1: Agent-specific config     (agent.llm_driver_config_id)
     * Tier 2: Principal preferred config (principal_preferences.preferred_llm_config_id)
     * Tier 3: Principal default config   (principal_id = agent's principal, is_default = true)
     * Tier 4: Global default            (is_global=true, is_default=true)
     *
     * A tier-1 / tier-2 configuration is only usable when it is global or
     * owned by the agent's own principal: a private configuration belongs
     * to the principal that pays for it, so a reference that outlived its
     * authorisation — an agent that was transferred, or a row written
     * before the reference was checked — must not spend another
     * principal's provider account.
     *
     * Tier 3 is the principal's own "Set as default" row — the control the
     * group page offers. Without it the button did nothing at runtime.
     * A global row carries `principal_id = null`, so it cannot be matched
     * by this tier and stays in tier 4.
     *
     * The source names both the tier and the principal it came from, so the
     * frontend can render "group default" against a group-owned agent and
     * "user default" against a personal one without re-implementing the
     * cascade. The values are part of the API contract:
     * `agent`, `group_preference`, `user_preference`, `group_default`,
     * `user_default`, `global_default`, `none`.
     *
     * @return array{config: ?LLMDriverConfiguration, source: string}
     */
    public function resolveEffectiveConfigForAgent(Agent $agent): array
    {
        $principalId = (int) $agent->principal_id;

        $config = $this->resolveAgentTier($agent, $principalId);
        if ($config !== null) {
            return ['config' => $config, 'source' => 'agent'];
        }

        $scope = $this->principalScope($principalId);

        $config = $this->resolvePreferenceTier($principalId);
        if ($config !== null) {
            return ['config' => $config, 'source' => $scope . '_preference'];
        }

        return $this->resolveDefaultTiers($principalId, $scope);
    }

    /**
     * The configuration an agent runs with. The tier it came from is
     * {@see self::resolveEffectiveConfigForAgent()}'s answer.
     */
    public function getEffectiveConfigForAgent(Agent $agent): ?LLMDriverConfiguration
    {
        return $this->resolveEffectiveConfigForAgent($agent)['config'];
    }

    /**
     * The tiers below the preference: the principal's own default row, then
     * the global default, then nothing.
     *
     * @return array{config: ?LLMDriverConfiguration, source: string}
     */
    private function resolveDefaultTiers(int $principalId, string $scope): array
    {
        $config = $this->principalDefaultConfig($principalId);
        if ($config !== null) {
            return ['config' => $config, 'source' => $scope . '_default'];
        }

        $config = $this->globalDefaultConfig();
        if ($config !== null) {
            return ['config' => $config, 'source' => 'global_default'];
        }

        return ['config' => null, 'source' => 'none'];
    }

    private function resolveAgentTier(Agent $agent, int $principalId): ?LLMDriverConfiguration
    {
        if ($agent->llm_driver_config_id === null) {
            return null;
        }

        $config = LLMDriverConfiguration::find($agent->llm_driver_config_id);
        if ($config === null || !$this->isUsableByPrincipal($config, $principalId)) {
            return null;
        }

        return $config;
    }

    private function resolvePreferenceTier(int $principalId): ?LLMDriverConfiguration
    {
        $config = $this->getPrincipalPreferredConfig($principalId);
        if ($config === null || !$this->isUsableByPrincipal($config, $principalId)) {
            return null;
        }

        return $config;
    }

    /**
     * The principal's own default row. Scoped by `principal_id`, so it
     * never matches a global row (whose `principal_id` is null) — the
     * global default stays in the tier below.
     *
     * The write paths keep one default per principal, but a row written
     * before that rule existed can leave two behind; the newest wins, so
     * the answer does not depend on the engine's row order.
     */
    private function principalDefaultConfig(int $principalId): ?LLMDriverConfiguration
    {
        return LLMDriverConfiguration::where('principal_id', $principalId)
            ->where('is_default', true)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    private function globalDefaultConfig(): ?LLMDriverConfiguration
    {
        return LLMDriverConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Whether the principal is a group's or a person's — the source label
     * depends on it. A principal row that cannot be read is treated as a
     * person's: the cascade still resolves, only the label reads "user".
     *
     * Read below tier 1 only — tier 1 names no scope, so the lookup
     * would be a wasted query on every LLM request.
     */
    private function principalScope(int $principalId): string
    {
        $principal = Principal::find($principalId);

        return $principal !== null && $principal->type === Principal::TYPE_GROUP ? 'group' : 'user';
    }

    private function isUsableByPrincipal(LLMDriverConfiguration $config, int $principalId): bool
    {
        return $config->is_global || (int) $config->principal_id === $principalId;
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

    /**
     * Clear a principal's preferred LLM configuration.
     *
     * The row carries the speech preference too, so only the LLM column is
     * cleared — deleting the row would take the principal's speech-to-text
     * preference with it.
     */
    public function unsetPrincipalPreferredConfig(int $principalId): void
    {
        PrincipalPreference::where('principal_id', $principalId)
            ->update(['preferred_llm_config_id' => null]);
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

    /**
     * Same auth gate as the original `loadDefaultableConfiguration`
     * helper but takes a `lockForUpdate` row lock first so two parallel
     * `setDefaultConfiguration` calls cannot each observe their target
     * row and the prior default row, then both flip `is_default = false`
     * before the transaction commits — which would leave the table with
     * either two defaults (data corruption) or no default (silent
     * outage) depending on the interleave. The lock guarantees the
     * {clear-others → set-new} pair runs serially against the prior
     * default row, matching the scoped pattern at
     * {@see \Spora\Http\GroupLlmConfigsController::setDefaultScopedConfigOrFail()}
     * (the per-group transaction that introduced this guard).
     */
    private function loadDefaultableConfigurationForUpdate(int $configId, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = LLMDriverConfiguration::where('id', $configId)
            ->lockForUpdate()
            ->first();
        $eligible = $config !== null && $config->is_global && $isAdmin;

        if (!$eligible) {
            return null;
        }

        return $config;
    }
}
