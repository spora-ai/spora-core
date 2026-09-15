<?php

declare(strict_types=1);

namespace Spora\Speech;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Models\PrincipalPreference;
use Spora\Models\SpeechProviderConfiguration;
use Spora\Services\PrincipalService;
use Throwable;

/**
 * Walks the five-tier speech provider cascade and answers the
 * resolved-class / source / config-id triple the
 * {@see SpeechToTextRegistry} exposes.
 *
 * Pulled out of {@see SpeechToTextRegistry} so the registry stays
 * under the SonarCloud S1448 20-method-per-class ceiling. The
 * registry keeps only the public surface (all / allProviders /
 * configuredProvider / describeWithConfig / describe / bindProviderLabel)
 * and delegates the cascade walk to this helper.
 *
 * The cascade:
 *   1. **Agent override** — `agents.speech_driver_config_id` →
 *      `speech_provider_configurations.provider_class`.
 *   2. **User preference** —
 *      `principal_preferences.preferred_speech_config_id` for the
 *      agent's user-principal.
 *   3. **Group preference** — every group the user belongs to, in
 *      `group_memberships.joined_at ASC` order; first match wins.
 *   4. **Global default** —
 *      `speech_provider_configurations WHERE is_global = true AND
 *      is_default = true`. First match wins, ordered `updated_at DESC,
 *      id DESC`.
 *   5. **First-registered-wins fallback** — the first provider in the
 *      constructor list, regardless of configuration state.
 *
 * Every tier validates that the resolved class is registered; an
 * unregistered class (e.g. the operator removed a plugin) is treated
 * as unset and the cascade falls through.
 */
final readonly class SpeechToTextCascadeResolver
{
    /**
     * @param list<SpeechToTextProviderInterface> $providers
     */
    public function __construct(
        private array $providers,
        private PrincipalService $principalService,
    ) {}

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    public function resolveEffectiveClassWithSource(int $userId, int $agentId = 0): array
    {
        $agentTier = $this->resolveAgentClass($agentId);
        if ($agentTier[0] !== null) {
            return $agentTier;
        }

        $prefTier = $this->resolvePreferredClassWithSource($userId);
        if ($prefTier[0] !== null) {
            return $prefTier;
        }

        return $this->resolveTailClass();
    }

    /**
     * Pick the configuration row that resolved the given provider
     * class for the caller, so the capability widget can show the
     * operator's per-config `display_name` next to the resolved
     * class. Returns `null` when the class was resolved via the
     * tier-5 fallback (no FK behind the choice).
     */
    public function resolveConfigForClass(string $providerClass, int $userId, int $agentId): ?SpeechProviderConfiguration
    {
        foreach (
            [$this->loadAgentSpeechConfig($agentId), $this->resolvePreferredConfigRow($userId)] as $candidate
        ) {
            if ($candidate !== null && $candidate->provider_class === $providerClass) {
                return $candidate;
            }
        }

        return SpeechProviderConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->where('provider_class', $providerClass)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolveTailClass(): array
    {
        $globalTier = $this->resolveGlobalDefaultClass();
        if ($globalTier[0] !== null) {
            return $globalTier;
        }

        return $this->resolveFallbackClass();
    }

    /**
     * @return array{0: string|null, 1: string, 2: int|null}
     */
    private function resolveAgentClass(int $agentId): array
    {
        $config = $this->loadAgentSpeechConfig($agentId);
        if ($config === null) {
            return [null, 'fallback', null];
        }

        return $this->resolveRegisteredClass($config->provider_class, 'agent', $config);
    }

    private function loadAgentSpeechConfig(int $agentId): ?SpeechProviderConfiguration
    {
        if ($agentId <= 0) {
            return null;
        }
        $agent = Agent::find($agentId);
        if ($agent === null || $agent->speech_driver_config_id === null) {
            return null;
        }

        return SpeechProviderConfiguration::find($agent->speech_driver_config_id);
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolvePreferredClassWithSource(int $userId): array
    {
        if ($this->registeredSttClasses() === [] || $userId <= 0) {
            return [null, 'fallback', null];
        }

        $userTier = $this->resolveUserPreferenceTier($userId);
        if ($userTier[0] !== null) {
            return $userTier;
        }

        return $this->resolveGroupPreferenceTier($userId);
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolveUserPreferenceTier(int $userId): array
    {
        try {
            $userPrincipalId = (int) $this->principalService->ensureUserPrincipal($userId)->id;
            $config = $this->configForPrincipalPreferred($userPrincipalId);
            if ($config !== null) {
                return [$config->provider_class, 'user_preference', (int) $config->id];
            }
        } catch (Throwable) {
            // fall through to the not-set tuple
        }

        return [null, 'fallback', null];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolveGroupPreferenceTier(int $userId): array
    {
        $groupRows = Capsule::table('group_memberships')
            ->join('principals', 'principals.group_id', '=', 'group_memberships.group_id')
            ->where('group_memberships.user_id', $userId)
            ->where('principals.type', Principal::TYPE_GROUP)
            ->orderBy('group_memberships.joined_at')
            ->orderBy('principals.id')
            ->select('principals.id as principal_id')
            ->get();

        $registered = $this->registeredSttClasses();
        foreach ($groupRows as $groupRow) {
            $config = $this->configForPrincipalPreferred((int) $groupRow->principal_id);
            if ($config !== null && in_array($config->provider_class, $registered, true)) {
                return [$config->provider_class, 'group_preference', (int) $config->id];
            }
        }

        return [null, 'fallback', null];
    }

    private function configForPrincipalPreferred(int $principalId): ?SpeechProviderConfiguration
    {
        $configId = PrincipalPreference::where('principal_id', $principalId)
            ->value('preferred_speech_config_id');
        if (!is_int($configId) || $configId <= 0) {
            return null;
        }
        $config = SpeechProviderConfiguration::find($configId);
        if ($config === null || !in_array($config->provider_class, $this->registeredSttClasses(), true)) {
            return null;
        }

        return $config;
    }

    /**
     * @return array{0: string|null, 1: string, 2: int|null}
     */
    private function resolveGlobalDefaultClass(): array
    {
        $defaultConfig = SpeechProviderConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        if ($defaultConfig === null) {
            return [null, 'global_default', null];
        }
        if (!in_array($defaultConfig->provider_class, $this->registeredSttClasses(), true)) {
            return [null, 'global_default', null];
        }

        return [$defaultConfig->provider_class, 'global_default', (int) $defaultConfig->id];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolveFallbackClass(): array
    {
        if ($this->providers === []) {
            return [null, null, null];
        }
        $first = $this->providers[0];

        return [$first::class, 'fallback', null];
    }

    /**
     * @return array{0: string|null, 1: string, 2: int|null}
     */
    private function resolveRegisteredClass(string $class, string $source, SpeechProviderConfiguration $config): array
    {
        if (in_array($class, $this->registeredSttClasses(), true)) {
            return [$class, $source, (int) $config->id];
        }

        return [null, 'fallback', null];
    }

    /**
     * @return list<string>
     */
    private function registeredSttClasses(): array
    {
        $classes = [];
        foreach ($this->providers as $provider) {
            $classes[] = $provider::class;
        }

        return $classes;
    }

    private function resolvePreferredConfigRow(int $userId): ?SpeechProviderConfiguration
    {
        if ($userId <= 0 || $this->registeredSttClasses() === []) {
            return null;
        }

        try {
            $userPrincipalId = (int) $this->principalService->ensureUserPrincipal($userId)->id;
            $preference = PrincipalPreference::where('principal_id', $userPrincipalId)->first();
            if ($preference !== null && $preference->preferred_speech_config_id !== null) {
                return SpeechProviderConfiguration::find((int) $preference->preferred_speech_config_id);
            }
        } catch (Throwable) {
            // fall through to null
        }

        return null;
    }
}
