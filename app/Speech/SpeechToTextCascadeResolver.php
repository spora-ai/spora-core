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
 * The cascade (per-agent path with `agentId > 0`):
 *   1. **Agent override** — `agents.speech_driver_config_id` →
 *      `speech_provider_configurations.provider_class`.
 *   2. **Principal preference** — `principal_preferences.preferred_speech_config_id`
 *      for the AGENT's principal (user-principal OR group-principal,
 *      depending on `agents.principal_id`). This is the critical fix:
 *      a group-owned agent must consult its group's preference, not
 *      the caller's user-principal preference.
 *   3. **Global default** —
 *      `speech_provider_configurations WHERE is_global = true AND
 *      is_default = true`. First match wins, ordered `updated_at DESC,
 *      id DESC`.
 *   4. **No config → null** — if no tier above resolved a class,
 *      the cascade returns `[null, null, null]`. The previous
 *      "fallback to first registered class" tier surfaced the class
 *      in the SPA capability badge ("Using OpenAI Compatible
 *      (fallback)") even when no FK config existed, making the UI
 *      claim the operator had a working STT provider when they did
 *      not. SpeechToTextRegistry's `configuredProvider()` already
 *      gates on `isConfigured()` so transcribe calls didn't fail
 *      silently — but the badge did, and the operator couldn't tell.
 *      The SPA's `cascadeBadge` reads `effective_class === null` and
 *      shows "No speech provider configured" instead.
 *
 * The cascade (caller-scoped path with `agentId <= 0`, e.g. the
 * composer recording button which has no agent context):
 *   1. **User preference** — the caller's own user-principal preference.
 *   2. **Group preference** — every group the caller belongs to, in
 *      `group_memberships.joined_at ASC` order; first match wins.
 *   3. **Global default** — same as above.
 *   4. **No config → null** — same as above.
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

        $prefTier = $this->resolvePreferredClassWithSource($userId, $agentId);
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
            [$this->loadAgentSpeechConfig($agentId), $this->resolvePreferredConfigRow($userId, $agentId)] as $candidate
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
            // The agent row exists but doesn't pin a `speech_driver_config_id`
            // — distinct from the tier-5 fallback (no FK behind the choice).
            // Use 'unconfigured' so any future caller that doesn't gate on
            // `$class !== null` can disambiguate.
            return [null, 'unconfigured', null];
        }
        if (!in_array($config->provider_class, $this->registeredSttClasses(), true)) {
            // FK points at an unregistered class (plugin removed) —
            // treat as unconfigured rather than the registered fallback.
            return [null, 'unconfigured', null];
        }

        return [$config->provider_class, 'agent', (int) $config->id];
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
     * Pick the principal we should consult for tier 2 (preference).
     *
     * Per-agent reads must reflect the AGENT's principal (`agents.principal_id`),
     * not the caller's user-principal — otherwise a group-owned agent would
     * resolve to the operator's personal preference and report `'user_preference'`
     * in the capability badge, when the agent's principal is actually the group.
     *
     * Caller-scoped reads (no agent) have no principal to attach to, so they
     * fall back to the caller-only path (`resolveUserPreferenceTier` →
     * `resolveGroupPreferenceTier`) used by the composer recording button.
     *
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolvePreferredClassWithSource(int $userId, int $agentId = 0): array
    {
        if ($this->registeredSttClasses() === [] || $userId <= 0) {
            return [null, 'fallback', null];
        }

        return $this->resolvePreferredTierForCaller($userId, $agentId);
    }

    /**
     * Dispatch between the per-agent principal path and the caller-
     * scoped user → group path so {@see resolvePreferredClassWithSource}
     * stays under the S1142 3-return ceiling.
     *
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolvePreferredTierForCaller(int $userId, int $agentId): array
    {
        $agentPrincipal = $this->resolveAgentPrincipalForPreference($agentId);
        if ($agentPrincipal !== null) {
            return $this->resolveAgentPrincipalPreferredTier($agentPrincipal);
        }

        return $this->resolveCallerUserOrGroupPreferredTier($userId);
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolveAgentPrincipalPreferredTier(array $agentPrincipal): array
    {
        $registered = $this->registeredSttClasses();
        $config = $this->configForPrincipalPreferred($agentPrincipal['principal_id']);
        if ($config !== null && in_array($config->provider_class, $registered, true)) {
            $source = $agentPrincipal['type'] === Principal::TYPE_GROUP ? 'group_preference' : 'user_preference';

            return [$config->provider_class, $source, (int) $config->id];
        }

        return [null, 'fallback', null];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    private function resolveCallerUserOrGroupPreferredTier(int $userId): array
    {
        $userTier = $this->resolveUserPreferenceTier($userId);

        return $userTier[0] !== null
            ? $userTier
            : $this->resolveGroupPreferenceTier($userId);
    }

    /**
     * Resolve the agent's principal into the shape the cascade wants.
     * Returns `null` when `agentId` is unset/zero, the agent row is
     * missing, or its principal points at a malformed row (data
     * integrity defect — fall through to the caller-scoped path so the
     * cascade doesn't error out on the recording button).
     *
     * @return array{principal_id: int, type: string}|null
     */
    private function resolveAgentPrincipalForPreference(int $agentId): ?array
    {
        if ($agentId <= 0) {
            return null;
        }

        $agent = Agent::find($agentId);
        if ($agent === null) {
            return null;
        }

        return $this->buildAgentPrincipalRow((int) $agent->principal_id);
    }

    /**
     * @return array{principal_id: int, type: string}|null
     */
    private function buildAgentPrincipalRow(int $agentPrincipalId): ?array
    {
        $principal = Principal::find($agentPrincipalId);
        if ($principal === null) {
            return null;
        }

        return ['principal_id' => (int) $principal->id, 'type' => (string) $principal->type];
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
     * @return array{0: null, 1: null, 2: null}
     */
    private function resolveFallbackClass(): array
    {
        // No FK config exists at any tier — return null so the capability
        // badge reads "No speech provider configured" and
        // configuredProvider() short-circuits to null. The previous
        // "fallback to first registered class" tier masked the missing
        // config in the UI; the transcribe HTTP layer was already gated
        // on isConfigured() so calls didn't silently succeed, but the
        // operator had no way to tell from the SPA badge.
        return [null, null, null];
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

    /**
     * Resolve the actual `SpeechProviderConfiguration` row backing the
     * agent's preferred tier — used by `resolveConfigForClass()` to
     * surface the operator's per-config `display_name` in the badge.
     *
     * For agent-scoped reads, the principal is the agent's (looked up
     * via `resolveAgentPrincipalForPreference()`), not the caller's. For
     * caller-scoped reads (no agent), the principal is the caller's
     * user-principal — composer recording button falls through here.
     */
    private function resolvePreferredConfigRow(int $userId, int $agentId = 0): ?SpeechProviderConfiguration
    {
        if ($userId <= 0 || $this->registeredSttClasses() === []) {
            return null;
        }

        $agentPrincipal = $this->resolveAgentPrincipalForPreference($agentId);
        if ($agentPrincipal !== null) {
            return $this->configForPrincipalPreferred($agentPrincipal['principal_id']);
        }

        return $this->resolveCallerUserPrincipalPreferredConfig($userId);
    }

    /**
     * Resolve the caller-scoped preferred config: materialise the
     * caller's user-principal and look up its preferred config. Errors
     * from `ensureUserPrincipal()` fall through to `null`.
     */
    private function resolveCallerUserPrincipalPreferredConfig(int $userId): ?SpeechProviderConfiguration
    {
        try {
            $userPrincipalId = (int) $this->principalService->ensureUserPrincipal($userId)->id;

            return $this->configForPrincipalPreferred($userPrincipalId);
        } catch (Throwable) {
            return null;
        }
    }
}
