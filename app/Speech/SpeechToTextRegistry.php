<?php

declare(strict_types=1);

namespace Spora\Speech;

use Spora\Services\ToolConfigService;

/**
 * Discovers every plugin-contributed + core-shipped
 * {@see SpeechToTextProviderInterface} and picks the first configured
 * one for the transcribe endpoint.
 *
 * Selection model is **first-configured wins** — the order of providers
 * in the constructor is the order of preference. The container defines
 * that order by listing the merged class entries (core list +
 * `PluginLoader::speechToTextProviderClasses()`); per-class load order
 * is FIFO by plugin manifest discovery.
 *
 * Per-agent provider override is **out of scope** for v1. The registry
 * exposes {@see describe()} so a future per-agent picker UI can read the
 * available providers without changing this contract.
 *
 * Per-config label binding — only the core-shipped
 * {@see OpenAiCompatibleTranscriber} reads its `display_name` from the
 * operator's effective settings. The registry resolves the effective
 * config for the caller's user id (and optionally an agent id) and calls
 * {@see OpenAiCompatibleTranscriber::bindLabel()} once per
 * {@see describe()} / {@see configuredProvider()} call before reading
 * {@see SpeechToTextProviderInterface::getName()} /
 * {@see getDisplayName()}. Class-level providers (e.g. the Muse plugin's
 * `MuseTranscribeProvider`) keep their static `getName()` /
 * `getDisplayName()` because they have no `bindLabel()` method and the
 * registry's `instanceof` gate skips them.
 */
final readonly class SpeechToTextRegistry
{
    /**
     * @param list<SpeechToTextProviderInterface> $providers
     */
    public function __construct(
        private array $providers,
        private ToolConfigService $configService,
    ) {}

    /**
     * @return list<SpeechToTextProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * First provider whose effective config has a usable API key (for
     * {@see OpenAiCompatibleTranscriber}) or whose `isConfigured()` is
     * true (for class-level providers). The transcribe controller
     * translates `null` into HTTP 503 `SPEECH_PROVIDER_UNAVAILABLE`.
     *
     * Backward-compatible no-arg overload (anonymous callers) routes to
     * `configuredProvider(0, null)`.
     */
    public function configuredProvider(?int $userId = null, ?int $agentId = null): ?SpeechToTextProviderInterface
    {
        $userId ??= 0;
        foreach ($this->providers as $provider) {
            if ($provider instanceof OpenAiCompatibleTranscriber) {
                $settings = $this->configService->getEffectiveSettings(
                    $provider::class,
                    $agentId ?? 0,
                    $userId,
                );
                $displayName = is_string($settings['display_name'] ?? null) ? trim($settings['display_name']) : '';
                if ($displayName !== '') {
                    $provider->bindLabel($displayName);
                }
                $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
                if ($apiKey === '') {
                    continue;
                }
                return $provider;
            }

            if ($provider->isConfigured()) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Wire shape backing {@see \Spora\Http\SpeechCapabilityController::index()}.
     *
     * Each row is `{name, display_name, configured, has_global_default, config_id}`.
     *
     *  - `name` / `display_name` reflect the resolved per-config label
     *    for {@see OpenAiCompatibleTranscriber} and the class-level
     *    `getName()` / `getDisplayName()` for everyone else.
     *  - `configured` is true when the resolved config has a non-empty
     *    `api_key` (OpenAI-compatible) or when `isConfigured()` returns
     *    true (class-level).
     *  - `has_global_default` is true when the operator has a global
     *    settings row for the provider class. (Today this is the closest
     *    approximation {@see ToolConfigService} provides — the Speech
     *    Provider Configuration plan replaces this with proper per-config
     *    semantics.)
     *  - `config_id` is always `null` — multi-instance rows only exist
     *    once the Speech Provider Configuration plan ships the new
     *    `stt_provider_configurations` table. The Capability endpoint is
     *    one-row-per-class for v1.
     *
     * Backward-compatible no-arg overload (anonymous callers) routes to
     * `describe(0, null)`.
     *
     * @return list<array{name: string, display_name: string, configured: bool, has_global_default: bool, config_id: null}>
     */
    public function describe(?int $userId = null, ?int $agentId = null): array
    {
        $userId ??= 0;
        $rows = [];
        foreach ($this->providers as $provider) {
            $hasGlobalDefault = false;
            $configured = false;

            if ($provider instanceof OpenAiCompatibleTranscriber) {
                $settings = $this->configService->getEffectiveSettings(
                    $provider::class,
                    $agentId ?? 0,
                    $userId,
                );
                $displayName = is_string($settings['display_name'] ?? null) ? trim($settings['display_name']) : '';
                if ($displayName !== '') {
                    $provider->bindLabel($displayName);
                }
                $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
                $configured = $apiKey !== '';
                $hasGlobalDefault = $this->configService->getGlobalSettings($provider::class) !== [];
            } else {
                // Class-level providers keep their static name + display
                // name; the configured flag is whatever they report.
                $configured = $provider->isConfigured();
            }

            $rows[] = [
                'name'               => $provider->getName(),
                'display_name'       => $provider->getDisplayName(),
                'configured'         => $configured,
                'has_global_default' => $hasGlobalDefault,
                'config_id'          => null,
            ];
        }
        return $rows;
    }
}
