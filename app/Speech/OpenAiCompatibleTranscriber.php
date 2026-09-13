<?php

declare(strict_types=1);

namespace Spora\Speech;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\ToolSetting;
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
        $request = $this->buildTranscribeRequest($bytes, $mimeType, $languageHint, $agentId, $userId);
        $payload = $this->sendTranscribeRequest($request);
        return $this->parseTranscribePayload($payload);
    }

    /**
     * @return array{url: string, headers: array<string, string>, body: array<string, mixed>, timeout: int}
     */
    /**
     * Build the HTTP request descriptor for `/audio/transcriptions`.
     *
     * `body` is what carries the file. Symfony's `HttpClient` interprets an
     * array-valued `body` as `application/x-www-form-urlencoded` when every
     * value is a scalar — Mistral and every other STT vendor reply 422
     * (`"cannot carry files"` / `invalid_request_no_input`). The trigger
     * to switch to `multipart/form-data` is **any value in the array being
     * a PHP stream resource** — see {@see \Symfony\Component\HttpClient\
     * HttpClientTrait::normalizeBody()}. We pre-build the multipart structure
     * here and translate it to `body` with `fopen()` on the file path in
     * {@see sendTranscribeRequest()} right before dispatch.
     *
     * The descriptor stays as `multipart` (not `body`) so the test
     * decorator can assert on field-level shape without juggling handles.
     *
     * @return array{
     *   url: string,
     *   headers: array<string, string>,
     *   multipart: list<array<string, mixed>>,
     *   timeout: int
     * }
     */
    private function buildTranscribeRequest(
        string $bytes,
        string $mimeType,
        ?string $languageHint,
        ?int $agentId,
        ?int $userId,
    ): array {
        $settings = $this->configService->getEffectiveSettings(self::class, $agentId ?? 0, $userId);

        $apiKey = $this->resolveApiKey($settings);
        $baseUrl = $this->resolveStringSetting($settings, 'base_url', self::DEFAULT_BASE_URL);
        $model = $this->resolveStringSetting($settings, 'model', self::DEFAULT_MODEL);
        $language = $this->resolveLanguage($settings, $languageHint);
        $timeoutRaw = $settings['http_timeout_seconds'] ?? (string) self::DEFAULT_TIMEOUT;
        $timeout = is_numeric($timeoutRaw) ? (int) $timeoutRaw : self::DEFAULT_TIMEOUT;

        [$uploadPath, $uploadName] = $this->writeTempUpload($bytes, $mimeType);

        $multipart = [
            [
                'name'        => 'file',
                'contents'    => $uploadPath,
                'filename'    => $uploadName,
                'contentType' => $mimeType,
            ],
            ['name' => 'model', 'contents' => $model],
        ];
        if ($language !== '') {
            $multipart[] = ['name' => 'language', 'contents' => $language];
        }

        return [
            'url'       => rtrim($baseUrl, '/') . '/audio/transcriptions',
            'headers'   => ['Authorization' => 'Bearer ' . $apiKey],
            'multipart' => $multipart,
            'timeout'   => $timeout,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveApiKey(array $settings): string
    {
        $apiKey = $this->resolveStringSetting($settings, 'api_key', '');
        if ($apiKey === '') {
            throw new SpeechToTextException(sprintf(
                'No API key configured for %s.',
                $this->getDisplayName(),
            ));
        }
        return $apiKey;
    }

    /**
     * Read a string setting with trim/empty fallback to `$default`.
     * Shared by every non-key, non-language text setting so the
     * trim+is_string+empty-default dance lives in one place.
     *
     * @param array<string, mixed> $settings
     */
    private function resolveStringSetting(array $settings, string $key, string $default): string
    {
        if (!is_string($settings[$key] ?? null)) {
            return $default;
        }
        $value = trim($settings[$key]);
        return $value !== '' ? $value : $default;
    }

    /**
     * Per-request hint wins — the LLM-recommended language for this
     * specific call. The setting is the default fallback when no hint
     * is supplied (operators who set a default language expect it to
     * apply to every recording without the LLM having to re-supply it).
     *
     * @param array<string, mixed> $settings
     */
    private function resolveLanguage(array $settings, ?string $languageHint): string
    {
        if ($languageHint !== null && $languageHint !== '') {
            return $languageHint;
        }
        return $this->resolveStringSetting($settings, 'language', '');
    }

    /**
     * Translate the `multipart` descriptor into Symfony's `body` shape
     * and dispatch the request. Each file-field's path becomes a
     * `fopen()` stream — that single trick is what flips Symfony's
     * content-type detector from `application/x-www-form-urlencoded`
     * to `multipart/form-data`. Text fields pass through as scalars.
     *
     * @param array{url: string, headers: array<string, string>, multipart: list<array<string, mixed>>, timeout: int} $request
     * @return array<string, mixed>
     */
    private function sendTranscribeRequest(array $request): array
    {
        $body = [];
        $openedStreams = [];
        try {
            foreach ($request['multipart'] as $part) {
                $name = (string) $part['name'];
                if (isset($part['filename'], $part['contents']) && is_string($part['contents']) && is_file($part['contents'])) {
                    // File field — open the temp file so Symfony's
                    // `normalizeBody()` sees a `resource` and emits a
                    // proper multipart body. Reaps the handle in the
                    // finally below so it survives across the call.
                    $openedStreams[] = $handle = fopen((string) $part['contents'], 'rb');
                    $body[$name] = $handle;
                } else {
                    $body[$name] = $part['contents'] ?? '';
                }
            }

            $response = $this->http->request('POST', $request['url'], [
                'headers' => $request['headers'],
                'body'    => $body,
                'timeout' => $request['timeout'],
            ]);
            $payload = $response->toArray(false);
        } catch (Throwable $e) {
            throw $this->classifyTransportFailure($e);
        } finally {
            foreach ($openedStreams as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        if ($response->getStatusCode() >= 400) {
            throw $this->classifyHttpFailure($response->getStatusCode(), $payload);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseTranscribePayload(array $payload): TranscriptionResult
    {
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
        $segments = $this->extractSegments($payload);

        return new TranscriptionResult(
            text: $text,
            language: $languageOut,
            durationMs: $durationMs,
            metadata: $segments === [] ? [] : ['segments' => $segments],
        );
    }

    /**
     * Write the audio bytes to a temp file for transport. Returns the
     * file path + a multipart-friendly filename. Symfony's `HttpClient`
     * multipart entries accept a file path string in `'contents'` and
     * stream the file off-disk rather than buffering it; an explicit
     * path also avoids the `UploadedFile` round-trip, which the
     * multipart API doesn't accept (it expects a file path or a stream,
     * not a `UploadedFile` wrapper).
     *
     * The temp file is left in place — `HttpClient` opens it, reads
     * it, and the OS reclaims it when nothing references it. The path
     * is returned to the caller for inclusion in the multipart entry.
     *
     * @return array{0: string, 1: string} [path, filename]
     */
    private function writeTempUpload(string $bytes, string $mimeType): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'spora_oai_stt_');
        if ($tmp === false) {
            throw new InvalidAudioException('Failed to stage audio for upload.');
        }
        file_put_contents($tmp, $bytes);
        $ext = $this->extensionFor($mimeType);
        return [$tmp, 'recording.' . $ext];
    }

    /**
     * Map a browser-native audio MIME to a file extension for the
     * multipart filename. Mirrors the v1 plugins' coverage
     * (webm/ogg/mp4/wav/mpeg/flac) so the only MIME that fails is one
     * no STT vendor would accept anyway.
     *
     * The `video/webm` case is a deliberate exception: the W3C MediaRecorder
     * spec labels audio-only WebM recordings as `video/webm` (the container
     * is identical to a video WebM; only the track list differs), and the
     * server's {@see \Spora\Services\MediaArchive\MimeSniffer} cannot tell
     * the two apart at the byte level. We accept the container here so
     * the audio-only recording survives the upload; the multipart
     * filename extension (`webm`) keeps every shipped STT vendor happy.
     */
    private function extensionFor(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'audio/webm', 'video/webm'   => 'webm',
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
        $candidates = [
            $payload['duration'] ?? null,
            $payload['usage']['seconds'] ?? null,
            $payload['usage']['prompt_audio_seconds'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate * 1000;
            }
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
        /** @var list<mixed> */
        return array_values($payload['segments']);
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
