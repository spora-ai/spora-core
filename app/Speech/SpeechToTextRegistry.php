<?php

declare(strict_types=1);

namespace Spora\Speech;

use Spora\Models\SpeechProviderConfiguration;
use Spora\Services\PrincipalService;

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
    private readonly SpeechToTextCascadeResolver $cascade;

    /**
     * @param list<SpeechToTextProviderInterface> $providers
     */
    public function __construct(
        private array $providers,
        PrincipalService $principalService,
    ) {
        $this->cascade = new SpeechToTextCascadeResolver($providers, $principalService);
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
