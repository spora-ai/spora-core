<?php

declare(strict_types=1);

namespace Spora\Speech;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigIdResolver;
use Spora\Services\ToolConfigService;
use Throwable;

/**
 * Discovers every plugin-contributed + core-shipped
 * {@see SpeechToTextProviderInterface} and picks the configured one for
 * the transcribe endpoint.
 *
 * Selection model is a **five-tier cascade** (mirrors
 * {@see \Spora\Services\LLMConfigPreferences::getEffectiveConfigForAgent()},
 * adapted for the speech storage split — global configs live in
 * `tool_configurations` while per-user / per-group configs live in
 * `tool_user_settings`, so the preferred-class key is a string and the
 * resolver walks the storage layer to find the matching row at the
 * current scope):
 *
 *   1. **Agent override** — `agent_tool_overrides` row for
 *      (`$agentId`, `tool_class`). If the row yields a configured
 *      provider, return it.
 *   2. **User preference** — `principal_preferences.preferred_speech_provider_class`
 *      for the agent's user-principal. If set and the row exists in
 *      `tool_user_settings` keyed by that principal, use it.
 *   3. **Group preference** — for every group the user belongs to
 *      (ordered by `group_memberships.joined_at ASC), check that
 *      group's preferred class; first match wins.
 *   4. **Global default** — `tool_configurations` row where
 *      `tool_class ∈ registered STT classes AND is_default = true`.
 *      First match wins (the service-enforced invariant keeps this to
 *      at most one row per registered class).
 *   5. **First-configured-wins fallback** — iterate providers in
 *      constructor order, return the first whose effective settings
 *      resolve to a configured state. (Legacy behaviour, preserved
 *      so an operator with no preferences set still gets the same
 *      provider they did before this PR.)
 *
 * If a stored class on tiers 2 / 3 is no longer registered (e.g. the
 * operator removed the plugin), the cascade treats it as unset and
 * falls through. Tier 4 only inspects registered STT classes so a
 * non-speech `is_default = true` row on another tool class can't
 * surface here.
 *
 * Per-config label binding — the registry resolves each provider's
 * effective `display_name` setting (when declared) and calls
 * {@see SpeechToTextProviderInterface::bindLabel()} once per
 * {@see describe()} call before reading `getName()` /
 * `getDisplayName()`. Both core-shipped {@see OpenAiCompatibleTranscriber}
 * and class-level providers that opt in (via the optional `bindLabel()`
 * method) participate; providers that don't declare a `display_name`
 * `#[ToolSetting]` silently fall through to their class-level defaults.
 * No-`bindLabel()` providers (e.g. {@see MuseTranscribeProvider} before
 * the meta-Muse plugin update) keep their static `getName()` /
 * `getDisplayName()` because the `method_exists` gate skips them.
 */
final readonly class SpeechToTextRegistry
{
    /**
     * @param list<SpeechToTextProviderInterface> $providers
     */
    public function __construct(
        private array $providers,
        private ToolConfigService $configService,
        private ToolConfigIdResolver $idResolver = new ToolConfigIdResolver(),
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

        // Tier 1: agent override for any registered class.
        foreach ($this->providers as $provider) {
            $class = $provider::class;
            if (\Spora\Models\AgentToolOverride::where('agent_id', $agentId)
                ->where('tool_class', $class)
                ->exists()
                && $this->resolveConfigured($provider, $agentId, $userId) !== null
            ) {
                return $provider;
            }
        }

        // Tiers 2 + 3: principal preference (user, then groups by joined_at ASC).
        $preferredClass = $this->resolvePreferredClass($userId, $agentId);
        if ($preferredClass !== null) {
            foreach ($this->providers as $provider) {
                if ($provider::class === $preferredClass
                    && $this->resolveConfigured($provider, $agentId, $userId) !== null
                ) {
                    return $provider;
                }
            }
        }

        // Tier 4: global default (registered STT classes only).
        $registeredSttClasses = $this->registeredSttClasses();
        if ($registeredSttClasses !== []) {
            $defaultRow = \Spora\Models\ToolConfiguration::whereIn('tool_class', $registeredSttClasses)
                ->where('is_default', true)
                ->orderBy('id')
                ->first();
            if ($defaultRow !== null) {
                $defaultClass = (string) $defaultRow->tool_class;
                foreach ($this->providers as $provider) {
                    if ($provider::class === $defaultClass
                        && $this->resolveConfigured($provider, $agentId, $userId) !== null
                    ) {
                        return $provider;
                    }
                }
            }
        }

        // Tier 5: existing first-configured-wins loop (unchanged).
        foreach ($this->providers as $provider) {
            if ($this->resolveConfigured($provider, $agentId, $userId) !== null) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Walk `principal_preferences.preferred_speech_provider_class` for
     * the agent's user-principal (tier 2) then for every group-principal
     * the user belongs to in `group_memberships.joined_at ASC` order
     * (tier 3). Returns the first registered STT class that resolves
     * to an actual settings row in `tool_user_settings`, or `null` if
     * no preference resolves. Unregistered classes are treated as unset.
     *
     * The row-existence check (vs just reading the preference column)
     * is what makes the cascade match the LLM pattern — tier 2 in
     * `LLMConfigPreferences` falls through when the pointed-at config
     * has been deleted, and we mirror that here so a stale pointer
     * doesn't pick a half-configured provider.
     */
    private function resolvePreferredClass(int $userId, int $agentId): ?string
    {
        $registered = $this->registeredSttClasses();
        if ($registered === [] || $userId <= 0) {
            return null;
        }

        // Tier 2: user-principal preference.
        try {
            $userPrincipalId = $this->principalService->ensureUserPrincipal($userId)->id;
        } catch (Throwable) {
            return null;
        }

        $userPreferred = \Spora\Models\PrincipalPreference::where('principal_id', $userPrincipalId)
            ->value('preferred_speech_provider_class');
        if (is_string($userPreferred)
            && in_array($userPreferred, $registered, true)
            && $this->userOrGroupRowExists($userPrincipalId, $userPreferred)
        ) {
            return $userPreferred;
        }

        // Tier 3: group-principal preferences, ordered by joined_at ASC.
        $groupRows = Capsule::table('group_memberships')
            ->join('principals', 'principals.group_id', '=', 'group_memberships.group_id')
            ->where('group_memberships.user_id', $userId)
            ->where('principals.type', \Spora\Models\Principal::TYPE_GROUP)
            ->orderBy('group_memberships.joined_at')
            ->orderBy('principals.id')
            ->select('principals.id as principal_id', 'principals.group_id')
            ->get();

        foreach ($groupRows as $groupRow) {
            $groupPreferred = \Spora\Models\PrincipalPreference::where('principal_id', $groupRow->principal_id)
                ->value('preferred_speech_provider_class');
            if (is_string($groupPreferred)
                && in_array($groupPreferred, $registered, true)
                && $this->userOrGroupRowExists((int) $groupRow->principal_id, $groupPreferred)
            ) {
                return $groupPreferred;
            }
        }

        return null;
    }

    private function userOrGroupRowExists(int $principalId, string $providerClass): bool
    {
        return \Spora\Models\ToolUserSetting::where('principal_id', $principalId)
            ->where('tool_class', $providerClass)
            ->exists();
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

    private function resolveConfigured(
        SpeechToTextProviderInterface $provider,
        int $agentId,
        int $userId,
    ): ?SpeechToTextProviderInterface {
        return $provider instanceof OpenAiCompatibleTranscriber
            ? $this->resolveOpenAiCompatibleProvider($provider, $agentId, $userId)
            : $this->resolveGenericProvider($provider);
    }

    private function resolveGenericProvider(SpeechToTextProviderInterface $provider): ?SpeechToTextProviderInterface
    {
        return $provider->isConfigured() ? $provider : null;
    }

    private function resolveOpenAiCompatibleProvider(
        OpenAiCompatibleTranscriber $provider,
        int $agentId,
        int $userId,
    ): ?SpeechToTextProviderInterface {
        $settings = $this->configService->getEffectiveSettings($provider::class, $agentId, $userId);
        $displayName = is_string($settings['display_name'] ?? null) ? trim($settings['display_name']) : '';
        if ($displayName !== '') {
            $provider->bindLabel($displayName);
        }
        $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
        return $apiKey !== '' ? $provider : null;
    }

    /**
     * Wire shape backing {@see \Spora\Http\SpeechCapabilityController::index()}.
     *
     * Each row is `{name, display_name, configured, has_global_default, config_id,
     * effective_class, effective_source}`. The two trailing fields are
     * the resolved-class cascade (mirrors the LLM `getEffectiveConfig*`
     * pattern): every row reports the same `effective_class` so the SPA
     * can render "Currently using: X" against the registry's view, and
     * `effective_source` is the tier label (`agent_override`,
     * `user_preference`, `group_preference`, `global_default`,
     * `fallback`, or `null` when nothing resolved).
     *
     *  - `name` / `display_name` reflect the resolved per-config label
     *    for providers that opt into `bindLabel()` (via the optional
     *    method on {@see SpeechToTextProviderInterface}), and the
     *    class-level `getName()` / `getDisplayName()` for everyone else.
     *  - `configured` is true when the resolved config has a non-empty
     *    `api_key` (OpenAI-compatible) or when `isConfigured()` returns
     *    true (class-level).
     *  - `has_global_default` is true when the operator has a global
     *    settings row for the provider class.
     *  - `config_id` is the row id of the global settings row when one
     *    exists for {@see OpenAiCompatibleTranscriber} (`null` when no
     *    row exists, and `null` for class-level providers that don't
     *    write to `tool_configurations`). The SPA deep-links the
     *    Capability row into the config edit form via this id.
     *
     * Backward-compatible no-arg overload (anonymous callers) routes to
     * `describe(0, null)`.
     *
     * @return list<array{name: string, display_name: string, configured: bool, has_global_default: bool, config_id: int|null, effective_class: string|null, effective_source: string|null}>
     */
    public function describe(?int $userId = null, ?int $agentId = null): array
    {
        $userId ??= 0;
        $agentId ??= 0;
        [$effectiveClass, $effectiveSource] = $this->resolveEffectiveClassWithSource($userId, $agentId);
        $rows = [];
        foreach ($this->providers as $provider) {
            $row = $provider instanceof OpenAiCompatibleTranscriber
                ? $this->describeOpenAiCompatible($provider, $agentId, $userId)
                : $this->describeGeneric($provider, $agentId, $userId);
            $row['effective_class']  = $effectiveClass;
            $row['effective_source'] = $effectiveSource;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveEffectiveClassWithSource(int $userId, int $agentId): array
    {
        // Tier 1: agent override.
        foreach ($this->providers as $provider) {
            $class = $provider::class;
            if (\Spora\Models\AgentToolOverride::where('agent_id', $agentId)
                ->where('tool_class', $class)
                ->exists()
            ) {
                return [$class, 'agent_override'];
            }
        }

        // Tiers 2 + 3.
        $preferredSource = $this->resolvePreferredClassWithSource($userId);
        if ($preferredSource[0] !== null) {
            return [$preferredSource[0], $preferredSource[1]];
        }

        // Tier 4: global default.
        $registeredSttClasses = $this->registeredSttClasses();
        if ($registeredSttClasses !== []) {
            $defaultRow = \Spora\Models\ToolConfiguration::whereIn('tool_class', $registeredSttClasses)
                ->where('is_default', true)
                ->orderBy('id')
                ->first();
            if ($defaultRow !== null) {
                return [(string) $defaultRow->tool_class, 'global_default'];
            }
        }

        // Tier 5: fallback — first registered STT class.
        if ($this->providers !== []) {
            return [$this->providers[0]::class, 'fallback'];
        }

        return [null, null];
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

        $userPreferred = \Spora\Models\PrincipalPreference::where('principal_id', $userPrincipalId)
            ->value('preferred_speech_provider_class');
        if (is_string($userPreferred)
            && in_array($userPreferred, $registered, true)
            && $this->userOrGroupRowExists($userPrincipalId, $userPreferred)
        ) {
            return [$userPreferred, 'user_preference'];
        }

        $groupRows = Capsule::table('group_memberships')
            ->join('principals', 'principals.group_id', '=', 'group_memberships.group_id')
            ->where('group_memberships.user_id', $userId)
            ->where('principals.type', \Spora\Models\Principal::TYPE_GROUP)
            ->orderBy('group_memberships.joined_at')
            ->orderBy('principals.id')
            ->select('principals.id as principal_id')
            ->get();

        foreach ($groupRows as $groupRow) {
            $groupPreferred = \Spora\Models\PrincipalPreference::where('principal_id', $groupRow->principal_id)
                ->value('preferred_speech_provider_class');
            if (is_string($groupPreferred)
                && in_array($groupPreferred, $registered, true)
                && $this->userOrGroupRowExists((int) $groupRow->principal_id, $groupPreferred)
            ) {
                return [$groupPreferred, 'group_preference'];
            }
        }

        return [null, 'fallback'];
    }

    /**
     * @return array{name: string, display_name: string, configured: bool, has_global_default: bool, config_id: int|null, effective_class: string|null, effective_source: string|null}
     */
    private function describeOpenAiCompatible(OpenAiCompatibleTranscriber $provider, int $agentId, int $userId): array
    {
        $settings = $this->configService->getEffectiveSettings($provider::class, $agentId, $userId);
        $displayName = is_string($settings['display_name'] ?? null) ? trim($settings['display_name']) : '';
        if ($displayName !== '') {
            $provider->bindLabel($displayName);
        }
        $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';

        return [
            'name'               => $provider->getName(),
            'display_name'       => $provider->getDisplayName(),
            'configured'         => $apiKey !== '',
            'has_global_default' => $this->configService->getGlobalSettings($provider::class) !== [],
            'config_id'          => $this->idResolver->globalConfigId($provider::class),
            'effective_class'    => null,
            'effective_source'   => null,
        ];
    }

    /**
     * Class-level providers keep their static name + display name; the
     * configured flag is whatever they report. They don't read from
     * `tool_configurations` so `has_global_default` and `config_id` stay
     * null/false on the wire shape.
     *
     * If the provider implements an OPTIONAL `bindLabel(string $label)`
     * method (any visibility — the registry uses `method_exists`), the
     * resolved `display_name` ToolSetting — when declared and non-empty —
     * is bound before reading `getName()` / `getDisplayName()` so the
     * operator's per-config rename surfaces. Class-level providers that
     * don't declare a `display_name` ToolSetting (or whose resolved value
     * is empty) silently keep their class-level defaults — the bindLabel
     * call is skipped for empty values so `bindLabel('')` doesn't blank
     * the existing label.
     *
     * @return array{name: string, display_name: string, configured: bool, has_global_default: bool, config_id: int|null, effective_class: string|null, effective_source: string|null}
     */
    private function describeGeneric(
        SpeechToTextProviderInterface $provider,
        int $agentId,
        int $userId,
    ): array {
        $settings = $this->configService->getEffectiveSettings($provider::class, $agentId, $userId);
        $displayName = is_string($settings['display_name'] ?? null) ? trim($settings['display_name']) : '';
        if ($displayName !== '' && method_exists($provider, 'bindLabel')) {
            $provider->bindLabel($displayName);
        }

        return [
            'name'               => $provider->getName(),
            'display_name'       => $provider->getDisplayName(),
            'configured'         => $provider->isConfigured(),
            'has_global_default' => false,
            'config_id'          => null,
            'effective_class'    => null,
            'effective_source'   => null,
        ];
    }
}
