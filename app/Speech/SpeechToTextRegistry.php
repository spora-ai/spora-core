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
        private PrincipalService $principalService,
    ) {}

    /**
     * @return list<SpeechToTextProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Alias of {@see self::all()} used by collaborators that need every
     * registered provider instance (e.g.
     * {@see \Spora\Services\SpeechProviderConfigPersistence::configResource()}
     * walking the registry to look up a class's display label). Kept as
     * a separate method so the call sites read naturally without
     * obscuring the internal provider list.
     *
     * @return list<SpeechToTextProviderInterface>
     */
    public function allProviders(): array
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

        [$class, $source, $configId] = $this->resolveEffectiveClassWithSource($userId, $agentId);
        if ($class === null) {
            return null;
        }

        // Tiers 1-4 picked a specific class — return its provider,
        // but only when it self-reports configured (the
        // `isConfigured()` gate that {@see SpeechToTextProviderInterface}
        // documents; see the docblock on
        // {@see \Spora\Speech\OpenAiCompatibleTranscriber::isConfigured()}
        // for the optimistic-default rationale). Tier 5 (fallback)
        // applies the same gate so a non-configured provider is never
        // handed to the transcribe call regardless of which tier
        // resolved the class.
        foreach ($this->providers as $provider) {
            if ($provider::class !== $class) {
                continue;
            }
            if (!$provider->isConfigured()) {
                continue;
            }
            if ($configId !== null) {
                $this->bindProviderLabel($provider, $configId);
            }
            return $provider;
        }
        return null;
    }

    /**
     * Resolve the effective provider per provider row, returning the
     * data the {@see SpeechCapabilityController} needs to render the
     * "Currently using: X" widget: `class`, `source`, `config_id`,
     * `display_name`.
     *
     * For tiers 1-4 the resolved class IS the provider's class and
     * `config_id` / `display_name` come from the FK target. For tier 5
     * (fallback) the resolved class is the first registered provider
     * with no FK backing it — `config_id` is `null` and the
     * provider's static `getDisplayName()` is returned.
     *
     * Every iterated provider has `bindLabel()` invoked (when
     * available) so the `display_name` reflects the operator's
     * per-config override.
     *
     * @return list<array{
     *     name: string,
     *     display_name: string,
     *     configured: bool,
     *     effective_class: string,
     *     effective_source: string,
     *     effective_config_id: int|null
     * }>
     */
    public function describeWithConfig(?int $userId, ?int $agentId = null): array
    {
        $userId ??= 0;
        $agentId ??= 0;

        [$effectiveClass, $effectiveSource, $effectiveConfigId] = $this->resolveEffectiveClassWithSource($userId, $agentId);

        $rows = [];
        foreach ($this->providers as $provider) {
            $providerClass = $provider::class;
            $resolvedConfig = $this->resolveConfigForClass($providerClass, $userId, $agentId);

            if ($resolvedConfig !== null) {
                $this->bindProviderLabel($provider, (int) $resolvedConfig->id);
            }

            $rows[] = [
                'name' => $provider->getName(),
                'display_name' => $provider->getDisplayName(),
                'configured' => $provider->isConfigured(),
                'effective_class' => $effectiveClass,
                'effective_source' => $effectiveSource,
                'effective_config_id' => $effectiveConfigId,
            ];
        }

        return $rows;
    }

    private function bindProviderLabel(SpeechToTextProviderInterface $provider, int $configId): void
    {
        $config = SpeechProviderConfiguration::find($configId);
        if ($config === null) {
            return;
        }
        $provider->bindLabel((string) $config->display_name);
    }

    /**
     * Look up the {@see SpeechProviderConfiguration} row that picked
     * the given provider class for the calling user. Returns `null`
     * when the class was resolved via the tier-5 fallback (no FK
     * behind the choice).
     */
    private function resolveConfigForClass(string $providerClass, int $userId, int $agentId): ?SpeechProviderConfiguration
    {
        $agentConfig = $this->loadAgentSpeechConfig($agentId);
        if ($agentConfig !== null && $agentConfig->provider_class === $providerClass) {
            return $agentConfig;
        }

        $preferred = $this->resolvePreferredConfigRow($userId);
        if ($preferred !== null && $preferred->provider_class === $providerClass) {
            return $preferred;
        }

        $default = SpeechProviderConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->where('provider_class', $providerClass)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        if ($default !== null) {
            return $default;
        }

        return null;
    }

    private function resolvePreferredConfigRow(int $userId): ?SpeechProviderConfiguration
    {
        if ($userId <= 0 || $this->registeredSttClasses() === []) {
            return null;
        }

        try {
            $userPrincipalId = (int) $this->principalService->ensureUserPrincipal($userId)->id;
        } catch (Throwable) {
            return null;
        }
        $preference = PrincipalPreference::where('principal_id', $userPrincipalId)->first();
        if ($preference === null || $preference->preferred_speech_config_id === null) {
            return null;
        }
        return SpeechProviderConfiguration::find((int) $preference->preferred_speech_config_id);
    }

    /**
     * Walk the cascade and return the resolved class FQCN, the tier
     * label that produced it, and the configuration row id that
     * backed the choice. Backed by
     * {@see self::resolveEffectiveClassWithSource()} so the transcribe
     * flow and the capability endpoint stay in lockstep.
     *
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    public function describe(?int $userId = null, ?int $agentId = null): array
    {
        $userId ??= 0;
        $agentId ??= 0;

        return $this->resolveEffectiveClassWithSource($userId, $agentId);
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
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
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolveTailClass(): array
    {
        // Tier 4: global default.
        $globalTier = $this->resolveGlobalDefaultClass();
        if ($globalTier[0] !== null) {
            return $globalTier;
        }
        // Tier 5: fallback — first registered STT class.
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
        } catch (Throwable) {
            return [null, 'fallback', null];
        }
        $config = $this->configForPrincipalPreferred($userPrincipalId);
        if ($config === null) {
            return [null, 'fallback', null];
        }
        return [$config->provider_class, 'user_preference', (int) $config->id];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolveGroupPreferenceTier(int $userId): array
    {
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
            $config = $this->configForPrincipalPreferred((int) $groupRow->principal_id);
            if ($config !== null && in_array($config->provider_class, $this->registeredSttClasses(), true)) {
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
        if ($config === null) {
            return null;
        }
        if (!in_array($config->provider_class, $this->registeredSttClasses(), true)) {
            return null;
        }
        return $config;
    }

    /**
     * @return array{0: string|null, 1: string, 2: int|null}
     */
    private function resolveGlobalDefaultClass(): array
    {
        // Most-recently-promoted default wins — ties broken by id DESC
        // so the same `updated_at` ordering is deterministic.
        $defaultConfig = SpeechProviderConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        if ($defaultConfig === null) {
            return [null, 'global_default', null];
        }
        $registered = $this->registeredSttClasses();
        if (!in_array($defaultConfig->provider_class, $registered, true)) {
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
}
