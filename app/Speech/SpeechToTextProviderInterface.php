<?php

declare(strict_types=1);

namespace Spora\Speech;

/**
 * Plugin-contributed speech-to-text provider.
 *
 * Plugins implementing this contract register themselves via the
 * {@see \Spora\Extensions\SporaExtensionInterface::speechToTextProviders()}
 * data hook. {@see SpeechToTextRegistry} discovers every
 * loaded plugin's providers and picks the first-configured one for any
 * given transcribe request.
 *
 * Implementation notes:
 *
 *  - {@see transcribe()} is the only required method. Implementations
 *    MUST be synchronous (sync-only is locked in v1 — see the plan).
 *  - Implementations MUST NOT include API keys in exception messages;
 *    see {@see SpeechToTextException}.
 *  - Implementations should accept any browser-native audio container
 *    (`audio/webm;codecs=opus`, `audio/ogg;codecs=opus`, `audio/mp4`,
 *    `audio/wav`, `audio/mpeg`) and reject unsupported MIME types via
 *    {@see InvalidAudioException}.
 *  - Configurable providers MUST implement
 *    `bindLabel(string $label): void`. {@see SpeechToTextRegistry} calls
 *    it before reading {@see getName()} / {@see getDisplayName()} so the
 *    operator's per-config `display_name` `#[ToolSetting]` overrides
 *    the class-level defaults. The label is rebound on every `describe()`
 *    call so multi-tenant requests don't bleed labels across calls.
 *    `bindLabel()` is part of the interface contract (declared below) —
 *    implementations that ignore it leave the provider class-level name
 *    in place but the registry still calls the method unconditionally,
 *    so a missing `bindLabel()` throws an "undefined method" fatal.
 *  - Providers MUST also implement `bindSettings(array $settings): void`.
 *    The registry decodes the resolved
 *    {@see \Spora\Models\SpeechProviderConfiguration::settings} blob and
 *    pushes the result into the provider before every transcribe call,
 *    so the provider sees the operator's v2-cascade settings (the
 *    `agents.speech_driver_config_id` →
 *    `principal_preferences.preferred_speech_config_id` →
 *    `speech_provider_configurations` cascade). Providers prefer bound
 *    settings over `ToolConfigService::getEffectiveSettings()` so the
 *    v2 UI is the single source of truth; legacy v1
 *    (`tool_user_settings`) operators keep working because the bound
 *    settings are only populated when the v2 row exists.
 */
interface SpeechToTextProviderInterface
{
    /** Stable key — used in logs, capability endpoint, settings. */
    public function getName(): string;

    /** Operator-facing label for the capability endpoint. */
    public function getDisplayName(): string;

    /**
     * All required settings (API key, etc.) are present and valid.
     *
     * The capability endpoint returns `configured: false` for providers
     * whose `isConfigured()` returns false. The transcribe endpoint
     * returns 503 when no configured provider is registered.
     */
    public function isConfigured(): bool;

    /**
     * Bind the operator's per-config `display_name` so the next
     * {@see getName()} / {@see getDisplayName()} call returns it
     * instead of the class-level default. The registry calls this
     * once per `describe()` / `configuredProvider()` invocation so
     * multi-tenant requests don't bleed labels across calls.
     *
     * Implementations MUST treat this as transient — the bound value
     * is reset on every call. Implementations without a per-config
     * `display_name` override can no-op.
     */
    public function bindLabel(string $label): void;

    /**
     * Bind the operator's per-config decoded settings (the
     * `#[ToolSetting]` schema for the resolved
     * {@see \Spora\Models\SpeechProviderConfiguration}). The registry
     * calls this on every `configuredProvider()` invocation so
     * multi-tenant requests don't bleed settings across calls.
     *
     * Implementations MUST treat the bound value as transient and
     * prefer it over `ToolConfigService::getEffectiveSettings()` in
     * `transcribe()` so the v2 cascade is the single source of truth.
     * Legacy v1 (`tool_user_settings`) operators keep working because
     * the registry only calls this hook when a v2
     * `SpeechProviderConfiguration` row exists.
     *
     * @param array<string, mixed> $settings decoded `settings` column
     *        (password fields already decrypted by
     *        {@see \Spora\Services\SpeechProviderConfigPersistence::decodeSettings()}).
     */
    public function bindSettings(array $settings): void;

    /**
     * Transcribe raw audio bytes to text.
     *
     * The optional `$agentId` and `$userId` let providers with per-agent
     * settings (via {@see \Spora\Services\ToolConfigService}) resolve the
     * correct cascade level. Plugins that don't need agent-scoped settings
     * can ignore both. The controller always passes them when available.
     *
     * @param string      $bytes        Raw audio bytes (already validated
     *                                   by the MIME sniffer upstream).
     * @param string      $mimeType     Container MIME, e.g. 'audio/webm;codecs=opus'.
     * @param string|null $languageHint BCP-47 hint (e.g. 'en-US') or null
     *                                   for auto-detect.
     * @param int|null     $agentId     Agent whose settings cascade applies,
     *                                   or null for global-only lookup.
     * @param int|null     $userId      Caller's user id (cascade principal),
     *                                   or null for anonymous.
     *
     * @throws InvalidAudioException  when the provider cannot ingest the MIME.
     * @throws SpeechToTextException  on provider error / network failure /
     *                                  missing settings. Messages MUST NOT
     *                                  contain API keys.
     */
    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult;
}
