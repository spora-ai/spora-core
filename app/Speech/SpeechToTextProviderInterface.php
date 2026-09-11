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
 *  - Configurable providers (see {@see OpenAiCompatibleTranscriber})
 *    expose a `bindLabel(string $label): void` method that the registry
 *    calls before reading {@see getName()} / {@see getDisplayName()} so
 *    the operator's per-config `display_name` overrides the class-level
 *    defaults. Class-level providers (`MuseTranscribeProvider`) do not
 *    expose this method; the registry gates the call on
 *    `instanceof OpenAiCompatibleTranscriber` and skips it for everything
 *    else. The label is reset on every `describe()` /
 *    `configuredProvider()` call so multi-tenant requests don't bleed
 *    labels across calls.
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
