<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\UserPreference;

/**
 * Default-resolution and user-preference logic for LLM configurations.
 *
 * Owns the three-tier fallback that decides which LLMDriverConfiguration
 * an agent should use (agent-specific → user preferred → global default),
 * the user-preference CRUD that backs tier 2, and the admin-only
 * "set the global default" flow that backs tier 3.
 *
 * The preferences path crosses user/agent boundaries, so this is the
 * highest-risk collaborator in the split: tests must keep the
 * tier-1/2/3 resolution working end-to-end.
 */
final class LLMConfigPreferences
{
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
        return $this->getUserPreferredConfig($userId);
    }

    /**
     * Resolves the effective LLMDriverConfiguration for an agent using three-tier fallback.
     *
     * Tier 1: Agent-specific config     (agent.llm_driver_config_id)
     * Tier 2: User's preferred config   (user_preferences.preferred_llm_config_id)
     * Tier 3: Global default           (is_global=true, is_default=true)
     */
    public function getEffectiveConfigForAgent(Agent $agent): ?LLMDriverConfiguration
    {
        // Tier 1: agent-specific
        if ($agent->llm_driver_config_id !== null) {
            $config = LLMDriverConfiguration::find($agent->llm_driver_config_id);
            if ($config !== null) {
                return $config;
            }
        }

        // Tier 2: user preferred config (via user_preferences)
        if ($agent->user_id !== null) {
            $config = $this->getUserPreferredConfig($agent->user_id);
            if ($config !== null) {
                return $config;
            }
        }

        // Tier 3: global default
        return LLMDriverConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->first();
    }

    public function getUserPreferredConfig(int $userId): ?LLMDriverConfiguration
    {
        $preference = UserPreference::where('user_id', $userId)->first();
        if ($preference === null || $preference->preferred_llm_config_id === null) {
            return null;
        }

        return LLMDriverConfiguration::find($preference->preferred_llm_config_id);
    }

    public function setUserPreferredConfig(int $userId, int $configId): bool
    {
        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return false;
        }

        // Config must belong to user OR be global
        if (!$config->is_global && $config->user_id !== $userId) {
            return false;
        }

        $preference = UserPreference::firstOrCreate(['user_id' => $userId]);
        $preference->preferred_llm_config_id = $configId;
        $preference->save();

        return true;
    }

    public function unsetUserPreferredConfig(int $userId): void
    {
        UserPreference::where('user_id', $userId)->delete();
    }

    private function loadDefaultableConfiguration(int $configId, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = LLMDriverConfiguration::find($configId);
        // Restrict to global configs only — personal default is now set via user preferences
        $eligible = $config !== null && $config->is_global && $isAdmin;

        if (!$eligible) {
            return null;
        }

        return $config;
    }
}
