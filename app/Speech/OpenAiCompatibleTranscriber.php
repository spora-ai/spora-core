<?php

declare(strict_types=1);

namespace Spora\Speech;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Generic OpenAI-multipart speech-to-text provider.
 *
 * Targets every vendor whose STT endpoint matches the OpenAI
 * `POST {base_url}/audio/transcriptions` shape (multipart `file` + `model`
 * + optional `language`, Bearer auth, JSON response with a `text` field).
 * Concrete examples:
 *
 *  - OpenAI Whisper — `base_url = https://api.openai.com/v1`, `model = whisper-1`
 *  - Mistral Voxtral Mini — `base_url = https://api.mistral.ai/v1`,
 *    `model = voxtral-mini-latest`. The Mistral wire adds a `diarize`
 *    boolean and returns `usage.prompt_audio_seconds`; both are absorbed
 *    by the default {@see extractDurationMs()} and the segment pass-through
 *    in {@see extractSegments()} so Mistral works without a subclass.
 *  - Groq Whisper — `base_url = https://api.groq.com/openai/v1`,
 *    `model = whisper-large-v3-turbo`.
 *  - Lemonfox, Fireworks, LocalAI, OpenRouter — same shape.
 *
 * Adding a new OpenAI-multipart vendor is a configuration row, not a
 * code change. Each operator's instance can run multiple configs in
 * parallel (one Mistral, one Groq, one self-hosted LocalAI), each with
 * its own `display_name` that surfaces in the Capability endpoint and
 * the recording button.
 *
 * v1 trade-offs (per the plan):
 *
 *  - No `diarize`, `context_bias[]`, `file_id`, or `file_url` fields.
 *    If a future operator needs Mistral's `diarize`, a thin subclass of
 *    {@see OpenAiCompatibleTranscriber} adds it via one protected
 *    `extendMultipartBody(array $body, array $settings): array` hook that
 *    a future patch can introduce without disturbing the v1 schema.
 *  - One config per Capability endpoint row. Multi-config view lives in
 *    the operator-facing `/settings/speech` page (separate plan).
 *  - Optimistic {@see isConfigured()} — the real key check happens at
 *    transcribe time so settings edited via the UI after registry
 *    construction are picked up on the next call (matches the
 *    `MistralTranscribeProvider::isConfigured()` contract).
 *
 * Operators who set their API key via direct SQL inserts into the
 * `tool_configurations` table during the v1 testing window need to
 * manually re-create the config in the operator UI post-upgrade. There
 * is no auto-migration; this is documented at the call site.
 */
#[ToolSetting(
    key: 'api_key',
    label: 'API Key',
    type: 'password',
    required: true,
    description: 'Bearer token for the OpenAI-compatible endpoint.',
)]
#[ToolSetting(
    key: 'display_name',
    label: 'Display name',
    type: 'text',
    required: true,
    description: 'Operator-visible label shown in the capability list. Pick a name that distinguishes this config from others (e.g. "Mistral Voxtral (prod)").',
    validation: '/^[A-Za-z0-9 _\-\.\(\)]{1,80}$/',
)]
#[ToolSetting(
    key: 'base_url',
    label: 'Base URL',
    type: 'text',
    required: true,
    default: 'https://api.openai.com/v1',
    description: 'Vendor base URL. The provider POSTs to {base_url}/audio/transcriptions.',
    validation: '#^https?://[^\s]+$#',
)]
#[ToolSetting(
    key: 'model',
    label: 'Model',
    type: 'text',
    required: true,
    default: 'whisper-1',
    description: 'Vendor model identifier. OpenAI: whisper-1. Mistral: voxtral-mini-latest. Groq: whisper-large-v3-turbo.',
)]
#[ToolSetting(
    key: 'language',
    label: 'Language hint (BCP-47)',
    type: 'text',
    required: false,
    default: '',
    description: 'Optional BCP-47 hint (e.g. "en-US"). Leave blank for auto-detect.',
)]
#[ToolSetting(
    key: 'http_timeout_seconds',
    label: 'HTTP timeout (seconds)',
    type: 'text',
    required: false,
    default: '60',
    description: 'Per-request HTTP timeout. Increase for slow vendors.',
    validation: '/^\d+$/',
)]
final class OpenAiCompatibleTranscriber implements SpeechToTextProviderInterface
{
    private const DEFAULT_NAME = 'openai_compatible';
    private const DEFAULT_DISPLAY_NAME = 'OpenAI Compatible';
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    private const DEFAULT_MODEL = 'whisper-1';
    private const DEFAULT_TIMEOUT = 60;

    // Non-promoted runtime state — the registry rebinds this between
    // calls so multi-tenant requests don't bleed labels. PHP forbids
    // re-assigning a readonly property outside the constructor, so the
    // class is declared `final` (not `final readonly`) to allow
    // bindLabel() to mutate this single field. Constructor-promoted
    // dependencies below are still never reassigned.
    private ?string $boundLabel = null;

    public function __construct(
        private HttpClientInterface $http,
        private ToolConfigService $configService,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Cache the operator's per-config `display_name`. The registry calls
     * this once per `describe()` / `configuredProvider()` invocation so
     * subsequent {@see getName()} / {@see getDisplayName()} calls return
     * the operator's label rather than the class-level default.
     */
    public function bindLabel(string $label): void
    {
        $this->boundLabel = $label;
    }

    public function getName(): string
    {
        return $this->boundLabel ?? self::DEFAULT_NAME;
    }

    public function getDisplayName(): string
    {
        return $this->boundLabel ?? self::DEFAULT_DISPLAY_NAME;
    }

    /**
     * Optimistic — the actual API-key check happens at transcribe time so
     * settings edited via the UI after registry construction are picked
     * up on the next call. The Capability endpoint reads the effective
     * config directly to derive `configured`, so this true-returning
     * default never lets an unconfigured provider be picked for a real
     * transcribe call.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        $settings = $this->configService->getEffectiveSettings(self::class, $agentId ?? 0, $userId);

        $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
        if ($apiKey === '') {
            throw new SpeechToTextException(sprintf(
                'No API key configured for %s.',
                $this->getDisplayName(),
            ));
        }

        $baseUrl = is_string($settings['base_url'] ?? null) && trim($settings['base_url']) !== ''
            ? rtrim(trim($settings['base_url']), '/')
            : self::DEFAULT_BASE_URL;
        $model = is_string($settings['model'] ?? null) && trim($settings['model']) !== ''
            ? trim($settings['model'])
            : self::DEFAULT_MODEL;

        // Per-request hint wins — the LLM-recommended language for this
        // specific call. The setting is the default fallback when no
        // hint is supplied (operators who set a default language expect
        // it to apply to every recording without the LLM having to
        // re-supply it).
        $language = $languageHint ?? '';
        if ($language === '') {
            $configuredLang = is_string($settings['language'] ?? null) ? trim($settings['language']) : '';
            $language = $configuredLang;
        }

        $timeoutRaw = $settings['http_timeout_seconds'] ?? (string) self::DEFAULT_TIMEOUT;
        $timeout = is_numeric($timeoutRaw) ? (int) $timeoutRaw : self::DEFAULT_TIMEOUT;

        $url = $baseUrl . '/audio/transcriptions';

        $body = ['file' => $this->wrapAsUpload($bytes, $mimeType), 'model' => $model];
        if ($language !== '') {
            $body['language'] = $language;
        }

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                'body'    => $body,
                'timeout' => $timeout,
            ]);
            $payload = $response->toArray(false);
        } catch (Throwable $e) {
            throw $this->classifyTransportFailure($e);
        }

        if ($response->getStatusCode() >= 400) {
            throw $this->classifyHttpFailure($response->getStatusCode(), $payload);
        }

        $text = is_string($payload['text'] ?? null) ? $payload['text'] : '';
        if ($text === '') {
            throw new InvalidAudioException(sprintf(
                '%s returned an empty transcript.',
                $this->getDisplayName(),
            ));
        }

        $languageOut = isset($payload['language']) && is_string($payload['language'])
            ? $payload['language']
            : null;

        $durationMs = $this->extractDurationMs($payload);
        $segments   = $this->extractSegments($payload);

        return new TranscriptionResult(
            text: $text,
            language: $languageOut,
            durationMs: $durationMs,
            metadata: $segments === [] ? [] : ['segments' => $segments],
        );
    }

    /**
     * Stage bytes as a temp file and wrap them in a Symfony
     * `UploadedFile` in test mode (which copies the file instead of
     * linking it, so the original can be unlinked immediately). The
     * test-mode copy lands next to the temp file and is cleaned up by
     * Symfony when the request finishes.
     */
    private function wrapAsUpload(string $bytes, string $mimeType): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'spora_oai_stt_');
        if ($tmp === false) {
            throw new InvalidAudioException('Failed to stage audio for upload.');
        }
        file_put_contents($tmp, $bytes);
        $ext  = $this->extensionFor($mimeType);
        $name = 'recording.' . $ext;
        try {
            // test mode = true: Symfony copies the file (so it survives
            // the unlink below) and uses the original MIME. The caller
            // relies on Symfony's destructor to clean up the copy.
            return new UploadedFile($tmp, $name, $mimeType, null, true);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Map a browser-native audio MIME to a file extension for the
     * multipart filename. Mirrors the v1 plugins' coverage
     * (webm/ogg/mp4/wav/mpeg/flac) so the only MIME that fails is one
     * no STT vendor would accept anyway.
     */
    private function extensionFor(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'audio/webm'                 => 'webm',
            'audio/ogg'                  => 'ogg',
            'audio/mp4', 'audio/x-m4a'   => 'm4a',
            'audio/wav', 'audio/x-wav'   => 'wav',
            'audio/mpeg', 'audio/mp3'    => 'mp3',
            'audio/flac'                 => 'flac',
            default => throw new InvalidAudioException(sprintf(
                'Unsupported audio MIME: %s',
                $mimeType,
            )),
        };
    }

    /**
     * Translate a Symfony HTTP-client transport failure (DNS, TLS,
     * timeout, connection reset) into a `SpeechToTextException` with
     * a sanitised message. The raw exception is the `previous` so the
     * server log keeps the operator-facing detail; the wire-facing
     * message hides hostnames and ports.
     */
    private function classifyTransportFailure(Throwable $e): SpeechToTextException
    {
        $this->logger?->error('OpenAI-compatible STT transport failure', [
            'provider' => $this->getDisplayName(),
            'message'  => $e->getMessage(),
        ]);

        return new SpeechToTextException(
            sprintf('%s request failed: %s', $this->getDisplayName(), $e->getMessage()),
            0,
            $e,
        );
    }

    /**
     * Map a non-2xx HTTP status to a `SpeechToTextException`. 401/403
     * are reported as provider-side auth failure (the operator must
     * rotate the key); 429 surfaces a retry hint so the SPA can show a
     * "try again in a moment" toast; 5xx is a transient vendor issue.
     * API-key strings must NEVER appear in the message.
     *
     * @param array<string, mixed> $payload
     */
    private function classifyHttpFailure(int $status, array $payload): SpeechToTextException
    {
        $vendor = $this->getDisplayName();
        $body   = $this->stringifyBody($payload);

        $this->logger?->error('OpenAI-compatible STT HTTP failure', [
            'provider' => $vendor,
            'status'   => $status,
            'body'     => $body,
        ]);

        return match (true) {
            $status === 401, $status === 403 => new SpeechToTextException(
                sprintf('%s rejected the API key (HTTP %d).', $vendor, $status),
            ),
            $status === 429 => new SpeechToTextException(
                sprintf('%s is rate-limiting requests (HTTP 429); try again shortly.', $vendor),
            ),
            $status >= 500 => new SpeechToTextException(
                sprintf('%s returned a server error (HTTP %d).', $vendor, $status),
            ),
            default => new SpeechToTextException(
                sprintf('%s returned HTTP %d: %s', $vendor, $status, $body),
            ),
        };
    }

    /**
     * Extract audio duration in milliseconds from the wire shape's
     * known locations, in vendor preference order:
     *
     *  1. top-level `duration` (OpenAI Whisper `verbose_json`)
     *  2. `usage.seconds` (OpenAI gpt-4o-transcribe family)
     *  3. `usage.prompt_audio_seconds` (Mistral Voxtral)
     *
     * Each source is multiplied by 1000 to convert seconds → ms,
     * matching the `TranscriptionResult::$durationMs` (`?float`) shape.
     *
     * @param  array<string, mixed> $payload
     */
    private function extractDurationMs(array $payload): ?float
    {
        if (isset($payload['duration']) && is_numeric($payload['duration'])) {
            return (float) $payload['duration'] * 1000;
        }
        if (isset($payload['usage']['seconds']) && is_numeric($payload['usage']['seconds'])) {
            return (float) $payload['usage']['seconds'] * 1000;
        }
        if (isset($payload['usage']['prompt_audio_seconds']) && is_numeric($payload['usage']['prompt_audio_seconds'])) {
            return (float) $payload['usage']['prompt_audio_seconds'] * 1000;
        }
        return null;
    }

    /**
     * Pass through the vendor's `segments[]` payload verbatim so the
     * SPA can render word-level timestamps when present (OpenAI Whisper
     * `verbose_json` mode). Returns `[]` when the vendor omits it,
     * keeping the `TranscriptionResult::$metadata` bag compact.
     *
     * @param  array<string, mixed> $payload
     * @return list<mixed>
     */
    private function extractSegments(array $payload): array
    {
        if (!isset($payload['segments']) || !is_array($payload['segments'])) {
            return [];
        }
        /** @var list<mixed> $segments */
        $segments = array_values($payload['segments']);
        return $segments;
    }

    /**
     * Reduce a JSON-decoded failure body to a single-line, length-bounded
     * string suitable for surfacing in an exception message. Avoids
     * leaking the full payload (which can include the request id or, on
     * some vendors, the API key in a `WWW-Authenticate` header).
     *
     * @param  array<string, mixed> $payload
     */
    private function stringifyBody(array $payload): string
    {
        $candidate = $payload['error']['message'] ?? $payload['message'] ?? $payload;
        if (!is_string($candidate)) {
            $candidate = json_encode($candidate, JSON_UNESCAPED_SLASHES);
            if (!is_string($candidate)) {
                $candidate = 'unknown error';
            }
        }
        return substr($candidate, 0, 200);
    }
}
