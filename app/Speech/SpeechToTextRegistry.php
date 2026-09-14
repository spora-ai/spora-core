<?php

declare(strict_types=1);

namespace Spora\Speech;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\PrincipalPreference;
use Spora\Models\SpeechProviderConfiguration;
use Spora\Services\PrincipalService;
use Throwable;

/**
 * Discovers every plugin-contributed + core-shipped
 * {@see SpeechToTextProviderInterface} and picks the configured one
 * for the transcribe endpoint.
 *
 * Selection model is a **five-tier cascade** (mirrors
 * {@see \Spora\Services\LLMConfigPreferences::getEffectiveConfigForAgent()},
 * adapted for the speech storage tables):
 *
 *   1. **Agent override** — `agents.speech_driver_config_id` →
 *      `speech_provider_configurations.provider_class`. If the FK
 *      points at an existing config whose class is registered, use
 *      it.
 *   2. **User preference** —
 *      `principal_preferences.preferred_speech_config_id` for the
 *      agent's user-principal. Same resolution as tier 1.
 *   3. **Group preference** — for every group the user belongs to
 *      (ordered by `group_memberships.joined_at ASC`), check that
 *      group's preference; first match wins.
 *   4. **Global default** —
 *      `speech_provider_configurations WHERE is_global = true AND
 *      is_default = true`. First match wins.
 *   5. **First-registered-wins fallback** — iterate providers in
 *      constructor order, return the first whose effective settings
 *      resolve to a configured state. (Legacy behaviour, preserved
 *      so an operator with no preferences / agent override / global
 *      default still gets the same fallback they did before this
 *      PR.)
 *
 * Every tier validates that the resolved class is registered; an
 * unregistered class (e.g. the operator removed a plugin) is treated
 * as unset and the cascade falls through, matching the existing
 * behaviour.
 *
 * The previous PR's per-agent override via `agent_tool_overrides`
 * was removed in migration 0084 — the new tier 1 reads from
 * `agents.speech_driver_config_id` directly. Migration 0084 also
 * cleans up the legacy override rows for core STT classes; plugin
 * operators either re-create via the new endpoint or leave the
 * legacy rows in place (the cascade ignores them anyway).
 */
final readonly class SpeechToTextRegistry
{
    /**
     * @param list<SpeechToTextProviderInterface> $providers
     */
    public function __construct(
        private array $providers,
        private PrincipalService $principalService = new PrincipalService(new \Spora\Services\PrincipalResolver()),
    ) {}

    /**
     * @return list<SpeechToTextProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Resolve the effective provider per the five-tier cascade.
     *
     * The transcribe controller translates `null` into HTTP 503
     * `SPEECH_PROVIDER_UNAVAILABLE`. Backward-compatible no-arg
     * overload (anonymous callers) routes to
     * `configuredProvider(0, null)`.
     */
    public function configuredProvider(?int $userId = null, ?int $agentId = null): ?SpeechToTextProviderInterface
    {
        $userId ??= 0;
        $agentId ??= 0;

        [$class, $source] = $this->resolveEffectiveClassWithSource($userId, $agentId);
        if ($class === null) {
            return null;
        }

        // Tiers 1-4 picked a specific class — return its provider.
        // Tier 5 fallback picks the first registered class that
        // self-reports as configured (the legacy first-configured-
        // wins semantics, preserved so the operator's transcribe
        // experience doesn't change when no preference / default
        // is set).
        foreach ($this->providers as $provider) {
            if ($provider::class !== $class) {
                continue;
            }
            if ($source === 'fallback' && !$provider->isConfigured()) {
                continue;
            }
            return $provider;
        }
        return null;
    }

    /**
     * Walk the cascade and return both the resolved class FQCN and
     * the tier label that produced it. Backed by
     * {@see self::resolveEffectiveClassWithSource()} so the transcribe
     * flow and the capability endpoint stay in lockstep.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function describe(?int $userId = null, ?int $agentId = null): array
    {
        $userId ??= 0;
        $agentId ??= 0;

        return $this->resolveEffectiveClassWithSource($userId, $agentId);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveEffectiveClassWithSource(int $userId, int $agentId = 0): array
    {
        // Tier 1: agent-specific config.
        $agentTier = $this->resolveAgentClass($agentId);
        if ($agentTier[0] !== null) {
            return $agentTier;
        }

        // Tiers 2 + 3: user / group preference (FK).
        $prefTier = $this->resolvePreferredClassWithSource($userId);
        if ($prefTier[0] !== null) {
            return $prefTier;
        }

        return $this->resolveTailClass();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveTailClass(): array
    {
        // Tier 4: global default.
        $globalClass = $this->resolveGlobalDefaultClass();
        if ($globalClass !== null) {
            return [$globalClass, 'global_default'];
        }
        // Tier 5: fallback — first registered STT class.
        return $this->resolveFallbackClass();
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveAgentClass(int $agentId): array
    {
        if ($agentId <= 0) {
            return [null, 'fallback'];
        }
        $agent = Agent::find($agentId);
        if ($agent === null || $agent->speech_driver_config_id === null) {
            return [null, 'fallback'];
        }
        $config = SpeechProviderConfiguration::find($agent->speech_driver_config_id);
        if ($config === null) {
            return [null, 'fallback'];
        }
        return $this->resolveRegisteredClass($config->provider_class, 'agent');
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolvePreferredClassWithSource(int $userId): array
    {
        $registered = $this->registeredSttClasses();
        if ($registered === [] || $userId <= 0) {
            return [null, 'fallback'];
        }

        try {
            $userPrincipalId = $this->principalService->ensureUserPrincipal($userId)->id;
        } catch (Throwable) {
            return [null, 'fallback'];
        }

        $userClass = $this->classForPrincipalPreferred((int) $userPrincipalId);
        if ($userClass !== null) {
            return [$userClass, 'user_preference'];
        }

        // Tier 3: group preferences, joined_at ASC.
        $groupRows = Capsule::table('group_memberships')
            ->join('principals', 'principals.group_id', '=', 'group_memberships.group_id')
            ->where('group_memberships.user_id', $userId)
            ->where('principals.type', \Spora\Models\Principal::TYPE_GROUP)
            ->orderBy('group_memberships.joined_at')
            ->orderBy('principals.id')
            ->select('principals.id as principal_id')
            ->get();

        foreach ($groupRows as $groupRow) {
            $class = $this->classForPrincipalPreferred((int) $groupRow->principal_id);
            if ($class !== null) {
                return [$class, 'group_preference'];
            }
        }

        return [null, 'fallback'];
    }

    private function classForPrincipalPreferred(int $principalId): ?string
    {
        $configId = PrincipalPreference::where('principal_id', $principalId)
            ->value('preferred_speech_config_id');
        if (!is_int($configId) || $configId <= 0) {
            return null;
        }

        $config = SpeechProviderConfiguration::find($configId);
        if ($config === null) {
            return null;
        }

        $registered = $this->registeredSttClasses();
        if (!in_array($config->provider_class, $registered, true)) {
            return null;
        }
        return $config->provider_class;
    }

    private function resolveGlobalDefaultClass(): ?string
    {
        $defaultConfig = SpeechProviderConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();
        if ($defaultConfig === null) {
            return null;
        }
        $registered = $this->registeredSttClasses();
        if (!in_array($defaultConfig->provider_class, $registered, true)) {
            return null;
        }
        return $defaultConfig->provider_class;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveFallbackClass(): array
    {
        if ($this->providers === []) {
            return [null, null];
        }
        $first = $this->providers[0];
        return [$first::class, 'fallback'];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveRegisteredClass(string $class, string $source): array
    {
        if (in_array($class, $this->registeredSttClasses(), true)) {
            return [$class, $source];
        }
        return [null, 'fallback'];
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
}
