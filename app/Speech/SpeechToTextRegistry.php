<?php

declare(strict_types=1);

namespace Spora\Speech;

use Closure;
use Spora\Models\SpeechProviderConfiguration;
use Spora\Services\PrincipalService;
use Spora\Services\SpeechProviderConfigPersistence;
use Spora\Services\SpeechProviderConfigValidator;

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
 *      is_default = true`. First match wins, ordered
 *      `updated_at DESC, id DESC`.
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
 * The cascade walk lives in {@see SpeechToTextCascadeResolver}; this
 * class stays small (one constructor + six public methods) so it
 * stays under the SonarCloud S1448 20-method-per-class ceiling.
 */
final readonly class SpeechToTextRegistry
{
    /**
     * Common-superset fallback MIME list when a provider class doesn't
     * declare its own `#[AcceptedAudioMime]` attributes. Order is
     * preference order — the SPA picker walks the list and the first
     * `MediaRecorder.isTypeSupported()` hit wins on the user's browser.
     */
    private const DEFAULT_PREFERRED_AUDIO_MIMES = [
        'audio/webm;codecs=opus',
        'audio/ogg;codecs=opus',
        'audio/mp4',
        'audio/webm',
        'audio/wav',
    ];

    private SpeechToTextCascadeResolver $cascade;

    /**
     * Lazy resolver into {@see SpeechProviderConfigPersistence}.
     * Injected as a Closure (not the service itself) to break the
     * `Validator -> Registry -> Persistence -> Validator` cycle —
     * see spora-core#242 for the cycle guard.
     *
     * @var (Closure(): ?SpeechProviderConfigPersistence)|null
     */
    private readonly ?Closure $persistenceResolver;

    /**
     * @param list<SpeechToTextProviderInterface> $providers
     * @param (Closure(): ?SpeechProviderConfigPersistence)|null $persistenceResolver
     */
    public function __construct(
        private readonly array $providers,
        PrincipalService $principalService,
        ?Closure $persistenceResolver = null,
    ) {
        $this->cascade = new SpeechToTextCascadeResolver($providers, $principalService);
        $this->persistenceResolver = $persistenceResolver;
    }

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

        [$class, , $configId] = $this->cascade->resolveEffectiveClassWithSource($userId, $agentId);
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
        //
        // `bindSettings()` MUST fire BEFORE `isConfigured()` is read so
        // providers can consult the bound v2 settings when answering
        // the gate (otherwise the gate stays optimistic for v2 rows
        // with an empty api_key and the transcribe call still throws).
        foreach ($this->providers as $provider) {
            if ($provider::class !== $class) {
                continue;
            }
            if ($configId !== null) {
                $this->bindProviderLabel($provider, $configId);
                $this->bindProviderSettings($provider, $configId);
            }
            if (!$provider->isConfigured()) {
                continue;
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
     * Each row's `class` is the provider's **own FQCN** — distinct
     * from `effective_class` (per-principal, identical across every
     * row). The SPA picker needs `class` to identify which row's
     * `preferred_audio_mimes` belongs to the resolved provider.
     *
     * @return list<array{
     *     name: string,
     *     class: class-string<SpeechToTextProviderInterface>,
     *     display_name: string,
     *     configured: bool,
     *     effective_class: string,
     *     effective_source: string,
     *     effective_config_id: int|null,
     *     preferred_audio_mimes: list<string>
     * }>
     */
    public function describeWithConfig(?int $userId, ?int $agentId = null): array
    {
        $userId ??= 0;
        $agentId ??= 0;

        [$effectiveClass, $effectiveSource, $effectiveConfigId] = $this->cascade->resolveEffectiveClassWithSource($userId, $agentId);

        $rows = [];
        foreach ($this->providers as $provider) {
            $providerClass = $provider::class;
            $resolvedConfig = $this->cascade->resolveConfigForClass($providerClass, $userId, $agentId);

            if ($resolvedConfig !== null) {
                $this->bindProviderLabel($provider, (int) $resolvedConfig->id);
            }

            $rows[] = [
                'name' => $provider->getName(),
                'class' => $providerClass,
                'display_name' => $provider->getDisplayName(),
                'configured' => $provider->isConfigured(),
                'effective_class' => $effectiveClass,
                'effective_source' => $effectiveSource,
                'effective_config_id' => $effectiveConfigId,
                // MiniMax rejects the Matroska container (HTTP 502 /
                // error 2013) so plugin authors declare OGG-over-Opus
                // ahead of WebM via `#[AcceptedAudioMime]`. The picker
                // falls back to the default when the provider opts out.
                'preferred_audio_mimes' => $this->preferredAudioMimesFor($providerClass),
            ];
        }

        return $rows;
    }

    /** @return list<string> Per-provider declared list, or the common-superset default when the provider opts out. */
    private function preferredAudioMimesFor(string $providerClass): array
    {
        $declared = SpeechProviderConfigValidator::collectAcceptedAudioMimes($providerClass);
        if ($declared !== []) {
            return $declared;
        }

        return self::DEFAULT_PREFERRED_AUDIO_MIMES;
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
     * Decode the resolved v2 row's `settings` blob and push it into the
     * provider via {@see SpeechToTextProviderInterface::bindSettings()}.
     * Not invoked on tier-5 fallback — {@see configuredProvider()} only
     * calls this when `$configId !== null`. Early-returns when the
     * registry was built without a persistence resolver (unit-test /
     * controller-less paths where there is no row to decode).
     */
    private function bindProviderSettings(SpeechToTextProviderInterface $provider, int $configId): void
    {
        if ($this->persistenceResolver === null) {
            return;
        }
        $persistence = ($this->persistenceResolver)();
        if (!$persistence instanceof SpeechProviderConfigPersistence) {
            return;
        }
        $config = SpeechProviderConfiguration::find($configId);
        if ($config === null) {
            return;
        }
        $rawSettings = $config->getRawOriginal('settings');
        $decoded = $persistence->decodeSettings($provider::class, $rawSettings);
        $provider->bindSettings($decoded);
    }

    /**
     * Walk the cascade and return the resolved class FQCN, the tier
     * label that produced it, and the configuration row id that
     * backed the choice. Forwarded to
     * {@see SpeechToTextCascadeResolver::resolveEffectiveClassWithSource()}
     * so the transcribe flow and the capability endpoint stay in
     * lockstep.
     *
     * @return array{0: string|null, 1: string|null, 2: int|null}
     */
    public function describe(?int $userId = null, ?int $agentId = null): array
    {
        $userId ??= 0;
        $agentId ??= 0;

        return $this->cascade->resolveEffectiveClassWithSource($userId, $agentId);
    }
}
