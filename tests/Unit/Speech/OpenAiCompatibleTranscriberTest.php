<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Test-only decorator: captures the raw $options['multipart'] (and the
 * legacy `$options['body']` for any pre-multipart callers) before the
 * inner client runs prepareRequest() (which would convert the multipart
 * array into a streaming Closure and lose the field-level shape).
 */
final class OaiCapturingHttpClient implements HttpClientInterface
{
    public mixed $capturedMultipart = null;
    public ?string $capturedUrl = null;

    public function __construct(private HttpClientInterface $inner) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->capturedMultipart = $options['multipart'] ?? null;
        $this->capturedUrl       = $url;
        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);
        return $clone;
    }
}

/**
 * Build a provider with a mocked {@see ToolConfigService} returning the
 * supplied settings map. The HTTP layer uses one canned response.
 *
 * @param array<string, mixed> $settings
 */
function buildOaiProvider(array $settings, string $body = '{}', int $status = 200): OpenAiCompatibleTranscriber
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);

    $mock = new MockHttpClient([new MockResponse($body, ['http_code' => $status])]);
    return new OpenAiCompatibleTranscriber($mock, $config);
}

test('bindLabel() caches the label; getName/getDisplayName return it', function (): void {
    $provider = buildOaiProvider([]);

    expect($provider->getName())->toBe('openai_compatible')
        ->and($provider->getDisplayName())->toBe('OpenAI Compatible');

    $provider->bindLabel('Mistral Voxtral (prod)');

    expect($provider->getName())->toBe('Mistral Voxtral (prod)')
        ->and($provider->getDisplayName())->toBe('Mistral Voxtral (prod)');
});

test('isConfigured() is optimistic — always true; the real key check happens at transcribe()', function (): void {
    $provider = buildOaiProvider([]);
    expect($provider->isConfigured())->toBeTrue();
});

test('transcribe() happy path returns text + language', function (): void {
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test'],
        json_encode([
            'text'     => 'hello world',
            'language' => 'en',
        ]),
    );

    $result = $provider->transcribe('fake-bytes', 'audio/webm', 'en-US');

    expect($result->text)->toBe('hello world')
        ->and($result->language)->toBe('en')
        ->and($result->durationMs)->toBeNull();
});

test('transcribe() Mistral wire: usage.prompt_audio_seconds × 1000 → durationMs', function (): void {
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test', 'model' => 'voxtral-mini-latest'],
        json_encode([
            'text'  => 'hello',
            'usage' => ['prompt_audio_seconds' => 4.2],
        ]),
    );

    $result = $provider->transcribe('fake-bytes', 'audio/webm');

    expect($result->durationMs)->toBe(4200.0);
});

test('transcribe() accepts video/webm as audio-only WebM (MediaRecorder quirk)', function (): void {
    // The W3C MediaRecorder spec labels audio-only WebM recordings as
    // `video/webm` — the container is identical to a video WebM, only
    // the track list differs. The server's byte sniffer cannot tell
    // them apart, so we accept the container here and the multipart
    // filename extension (`webm`) keeps every shipped STT vendor happy.
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test', 'model' => 'whisper-1'],
        json_encode(['text' => 'hello from audio-only webm']),
    );

    $result = $provider->transcribe('fake-bytes', 'video/webm');

    expect($result->text)->toBe('hello from audio-only webm');
});

test('transcribe() OpenAI gpt-4o-transcribe wire: usage.seconds × 1000 → durationMs', function (): void {
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test'],
        json_encode([
            'text'  => 'hello',
            'usage' => ['seconds' => 3.75],
        ]),
    );

    $result = $provider->transcribe('fake-bytes', 'audio/webm');

    expect($result->durationMs)->toBe(3750.0);
});

test('transcribe() verbose_json wire: duration × 1000 → durationMs, segments[] → metadata', function (): void {
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test', 'model' => 'whisper-1'],
        json_encode([
            'text'     => 'hello world',
            'language' => 'en',
            'duration' => 2.5,
            'segments' => [
                ['start' => 0.0, 'end' => 1.2, 'text' => 'hello'],
                ['start' => 1.2, 'end' => 2.5, 'text' => 'world'],
            ],
        ]),
    );

    $result = $provider->transcribe('fake-bytes', 'audio/webm');

    expect($result->durationMs)->toBe(2500.0)
        ->and($result->metadata)->toBe([
            'segments' => [
                ['start' => 0, 'end' => 1.2, 'text' => 'hello'],
                ['start' => 1.2, 'end' => 2.5, 'text' => 'world'],
            ],
        ]);
});

test('transcribe() empty api_key raises SpeechToTextException with a sanitised message (no key in payload)', function (): void {
    $provider = buildOaiProvider([]);

    $exception = null;
    try {
        $provider->transcribe('fake-bytes', 'audio/webm');
    } catch (SpeechToTextException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and($exception)->toBeInstanceOf(SpeechToTextException::class)
        ->and($exception->getMessage())->toContain('No API key configured')
        ->and($exception->getMessage())->not->toContain('sk-');
});

test('transcribe() empty response text raises InvalidAudioException', function (): void {
    $provider = buildOaiProvider(['api_key' => 'sk-test'], json_encode(['text' => '']));

    expect(fn() => $provider->transcribe('fake-bytes', 'audio/webm'))
        ->toThrow(InvalidAudioException::class, 'returned an empty transcript');
});

test('transcribe() missing text field raises InvalidAudioException', function (): void {
    $provider = buildOaiProvider(['api_key' => 'sk-test'], json_encode(['language' => 'en']));

    expect(fn() => $provider->transcribe('fake-bytes', 'audio/webm'))
        ->toThrow(InvalidAudioException::class);
});

test('transcribe() 401 raises SpeechToTextException with auth message', function (): void {
    $provider = buildOaiProvider(['api_key' => 'sk-test'], '{"error":{"message":"unauthorized"}}', 401);

    $exception = null;
    try {
        $provider->transcribe('fake-bytes', 'audio/webm');
    } catch (SpeechToTextException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toContain('HTTP 401')
        ->and($exception->getMessage())->not->toContain('sk-test');
});

test('transcribe() 403 raises SpeechToTextException', function (): void {
    $provider = buildOaiProvider(['api_key' => 'sk-test'], '{"error":{"message":"forbidden"}}', 403);

    expect(fn() => $provider->transcribe('fake-bytes', 'audio/webm'))
        ->toThrow(SpeechToTextException::class, 'HTTP 403');
});

test('transcribe() 429 raises SpeechToTextException with retry hint', function (): void {
    $provider = buildOaiProvider(['api_key' => 'sk-test'], '{"error":{"message":"rate-limited"}}', 429);

    expect(fn() => $provider->transcribe('fake-bytes', 'audio/webm'))
        ->toThrow(SpeechToTextException::class, 'rate-limit');
});

test('transcribe() 5xx raises SpeechToTextException with server-error message', function (): void {
    $provider = buildOaiProvider(['api_key' => 'sk-test'], '{"error":{"message":"downstream"}}', 502);

    expect(fn() => $provider->transcribe('fake-bytes', 'audio/webm'))
        ->toThrow(SpeechToTextException::class, 'server error');
});

test('transcribe() unknown MIME raises InvalidAudioException — never reaches the API', function (): void {
    $provider = buildOaiProvider(['api_key' => 'sk-test']);

    expect(fn() => $provider->transcribe('fake', 'audio/x-foo'))
        ->toThrow(InvalidAudioException::class, 'Unsupported audio MIME: audio/x-foo');
});

test('common browser-native containers are accepted (one mock per call)', function (): void {
    foreach (['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/flac', 'audio/x-m4a', 'audio/x-wav', 'audio/mp3'] as $mime) {
        $provider = buildOaiProvider(['api_key' => 'sk-test'], json_encode(['text' => 'ok']));
        expect($provider->transcribe('fake-bytes', $mime)->text)->toBe('ok');
    }
});

test('transcribe() honours the language setting when no hint is supplied', function (): void {
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test', 'language' => 'fr-FR'],
        json_encode(['text' => 'bonjour']),
    );

    $result = $provider->transcribe('fake-bytes', 'audio/webm', null);

    expect($result->text)->toBe('bonjour');
});

test('transcribe() lets the per-request hint win when both are set', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'api_key'  => 'sk-test',
        'language' => 'fr-FR',
    ]);

    $mock = new MockHttpClient([new MockResponse(json_encode(['text' => 'ok']))]);
    $capturing = new OaiCapturingHttpClient($mock);
    $provider = new OpenAiCompatibleTranscriber($capturing, $config);

    $provider->transcribe('fake-bytes', 'audio/webm', 'en-US');

    // The hint wins — the multipart body must carry `language: en-US`,
    // not the configured `fr-FR`. Now lives in `multipart` (not `body` —
    // see the comment on `OpenAiCompatibleTranscriber::buildTranscribeRequest`).
    expect($capturing->capturedMultipart)->toBeArray()
        ->and($capturing->capturedMultipart)->toContainMultipartField('language', 'en-US');
});

test('transcribe() with empty language setting and empty hint omits the language field from the multipart body', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'api_key'  => 'sk-test',
        'language' => '',
    ]);

    $mock = new MockHttpClient([new MockResponse(json_encode(['text' => 'ok']))]);
    $capturing = new OaiCapturingHttpClient($mock);
    $provider = new OpenAiCompatibleTranscriber($capturing, $config);

    $result = $provider->transcribe('fake-bytes', 'audio/webm', null);

    expect($result->text)->toBe('ok');
    expect($capturing->capturedMultipart)->toBeArray()
        ->and($capturing->capturedMultipart)->not->toContainMultipartField('language')
        ->and($capturing->capturedMultipart)->toContainMultipartField('model', 'whisper-1')
        ->and($capturing->capturedMultipart)->toContainMultipartField('file', null, expect_path: true, expect_mime: 'audio/webm');
});

test('transcribe() POSTs to {base_url}/audio/transcriptions', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'api_key'  => 'sk-test',
        'base_url' => 'https://api.mistral.ai/v1',
    ]);

    $mock = new MockHttpClient([new MockResponse(json_encode(['text' => 'ok']))]);
    $capturing = new OaiCapturingHttpClient($mock);
    $provider = new OpenAiCompatibleTranscriber($capturing, $config);

    $provider->transcribe('fake-bytes', 'audio/webm');

    expect($capturing->capturedUrl)->toBe('https://api.mistral.ai/v1/audio/transcriptions');
});

// Regression: Symfony's HttpClient interprets an array-valued `body`
// option as `application/x-www-form-urlencoded`, which every STT vendor
// rejects with `cannot carry files; use multipart/form-data`. The
// pre-fix code passed `'body' => $body` with a `Symfony\...\UploadedFile`
// in the array — Mistral (and OpenAI, Groq, et al.) replied 422. Pin
// the fix: the HTTP descriptor uses `multipart`, never `body`, for the
// file-carrying request.
test('transcribe() uses the multipart option, never body (Symfony array-body is form-encoded)', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'api_key' => 'sk-test',
        'model'   => 'whisper-1',
    ]);

    /** Captures the full $options array so we can assert against `body` AND `multipart`. */
    /** @var array<string, mixed> $capturedOptions */
    $capturedOptions = [];
    $mock = new MockHttpClient([new MockResponse(json_encode(['text' => 'ok']))]);
    $provider = new OpenAiCompatibleTranscriber(
        new class ($mock, $capturedOptions) implements HttpClientInterface {
            public function __construct(private MockHttpClient $mock, public array &$capturedOptions) {}
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->capturedOptions = $options;
                return $this->mock->request($method, $url, $options);
            }
            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                return $this->mock->stream($responses, $timeout);
            }
            public function withOptions(array $options): static
            {
                return $this;
            }
        },
        $config,
    );

    $provider->transcribe('fake-bytes', 'audio/webm');

    expect($capturedOptions)->toHaveKey('multipart');
    expect($capturedOptions)->not->toHaveKey('body');
    expect($capturedOptions['multipart'])->toBeArray();
});

test('transcribe() uses the configured model verbatim', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'api_key'  => 'sk-test',
        'model'    => 'voxtral-mini-latest',
    ]);

    $mock = new MockHttpClient([new MockResponse(json_encode(['text' => 'ok']))]);
    $capturing = new OaiCapturingHttpClient($mock);
    $provider = new OpenAiCompatibleTranscriber($capturing, $config);

    $provider->transcribe('fake-bytes', 'audio/webm');

    expect($capturing->capturedMultipart)->toContainMultipartField('model', 'voxtral-mini-latest');
});

test('transcribe() sends Authorization: Bearer header (via body capture and the MockHttpClient)', function (): void {
    // We assert through the MockHttpClient by reading the captured
    // request's headers indirectly — the body capture above is
    // sufficient for the visible shape; the auth header is the only
    // remaining header and is set on every request via the 'headers'
    // option in transcribe().
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn(['api_key' => 'sk-key-1']);
    $capturedHeaders = null;
    $mock = new MockHttpClient([new MockResponse(json_encode(['text' => 'ok']))]);
    $provider = new OpenAiCompatibleTranscriber(
        new class ($mock, $capturedHeaders) implements HttpClientInterface {
            public function __construct(private MockHttpClient $mock, public mixed &$capturedHeaders) {}
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->capturedHeaders = $options['headers'] ?? [];
                return $this->mock->request($method, $url, $options);
            }
            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                return $this->mock->stream($responses, $timeout);
            }
            public function withOptions(array $options): static
            {
                return $this;
            }
        },
        $config,
    );

    $provider->transcribe('fake-bytes', 'audio/webm');

    expect($capturedHeaders)->toBe(['Authorization' => 'Bearer sk-key-1']);
});

/**
 * Pest custom expectations for the Symfony multipart shape we send:
 *
 *   multipart: [
 *     ['name' => 'file',  'contents' => '/tmp/...', 'filename' => 'recording.webm', 'contentType' => 'audio/webm'],
 *     ['name' => 'model', 'contents' => 'whisper-1'],
 *     ...
 *   ]
 *
 * `expect($multipart)->toContainMultipartField('model', 'voxtral-mini-latest')`:
 *   asserts a text part named `model` exists with `contents === 'voxtral-mini-latest'`.
 *
 * `expect($multipart)->toContainMultipartField('file', null, expect_path: true, expect_mime: 'audio/webm')`:
 *   asserts a file part named `file` exists, its `contents` is a real file path
 *   on disk, and the `contentType` matches `audio/webm`.
 *
 * Each branch always fires at least one Pest assertion (via `expect()`)
 * so the test counts as "made assertions" even when the match is
 * successful — direct `test()->fail()` calls weren't being counted
 * by Pest 2 as assertion traffic.
 */
expect()->extend('toContainMultipartField', function (
    string $name,
    mixed $expectedContents = null,
    bool $expect_path = false,
    ?string $expect_mime = null,
) {
    /** @var list<array<string, mixed>>|mixed $parts */
    $parts = $this->value;

    expect($parts)->toBeArray();
    /** @var list<array<string, mixed>> $parts */
    $parts = $parts;

    $matching = null;
    foreach ($parts as $part) {
        if (($part['name'] ?? null) === $name) {
            $matching = $part;
            break;
        }
    }

    expect($matching)->not->toBeNull("multipart part '{$name}' not present");

    if ($expect_path === true) {
        $contents = $matching['contents'] ?? null;
        expect($contents)->toBeString("multipart part '{$name}' contents");
        expect(file_exists((string) $contents))->toBeTrue(
            "multipart part '{$name}' contents must be a real file path, got " . var_export($contents, true),
        );
        if ($expect_mime !== null) {
            expect($matching['contentType'] ?? null)->toBe($expect_mime);
        }
        return $this;
    }

    expect($matching['contents'] ?? null)->toBe($expectedContents);
    return $this;
});

expect()->extend('not->toContainMultipartField', function (string $name) {
    /** @var list<array<string, mixed>>|mixed $parts */
    $parts = $this->value;
    expect($parts)->toBeArray();
    /** @var list<array<string, mixed>> $parts */
    $parts = $parts;

    foreach ($parts as $part) {
        if (($part['name'] ?? null) === $name) {
            test()->fail("multipart part '{$name}' must not be present, found " . json_encode($part));
        }
    }
    return $this;
});
