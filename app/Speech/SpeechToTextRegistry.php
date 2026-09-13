<?php

declare(strict_types=1);

namespace Spora\Speech;

use Spora\Services\ToolConfigIdResolver;
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
            $candidate = $provider instanceof OpenAiCompatibleTranscriber
                ? $this->resolveOpenAiCompatibleProvider($provider, $agentId ?? 0, $userId)
                : $this->resolveGenericProvider($provider);
            if ($candidate !== null) {
                return $candidate;
            }
        }
        return null;
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
     * Each row is `{name, display_name, configured, has_global_default, config_id}`.
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
     * @return list<array{name: string, display_name: string, configured: bool, has_global_default: bool, config_id: int|null}>
     */
    public function describe(?int $userId = null, ?int $agentId = null): array
    {
        $userId ??= 0;
        $rows = [];
        foreach ($this->providers as $provider) {
            $rows[] = $provider instanceof OpenAiCompatibleTranscriber
                ? $this->describeOpenAiCompatible($provider, $agentId ?? 0, $userId)
                : $this->describeGeneric($provider, $agentId ?? 0, $userId);
        }
        return $rows;
    }

    /**
     * @return array{name: string, display_name: string, configured: bool, has_global_default: bool, config_id: int|null}
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
     * @return array{name: string, display_name: string, configured: bool, has_global_default: bool, config_id: int|null}
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
        ];
    }
}
