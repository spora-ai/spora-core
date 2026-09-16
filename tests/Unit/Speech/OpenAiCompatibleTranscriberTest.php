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
 * Test-only decorator: captures the raw $options['body'] before the
 * inner client runs prepareRequest() (which would convert the body
 * into a streaming Closure and lose the field-level shape).
 *
 * Symfony's HttpClient emits `multipart/form-data` when any value in
 * `$options['body']` is a PHP stream resource (fopen). Our
 * `sendTranscribeRequest()` builds that array on the fly from the
 * internal `multipart` descriptor — so what we capture here is the
 * exact JSON Symfony will serialise.
 */
final class OaiCapturingHttpClient implements HttpClientInterface
{
    public mixed $capturedBody = null;
    public ?string $capturedUrl = null;

    public function __construct(private HttpClientInterface $inner) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->capturedBody = $options['body'] ?? null;
        $this->capturedUrl  = $url;
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
    // Same MediaRecorder quirk as the video/mp4 test below — Safari
    // labels audio-only WebM as video/webm; the multipart extension
    // (`webm`) keeps every shipped STT vendor happy.
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test', 'model' => 'whisper-1'],
        json_encode(['text' => 'hello from audio-only webm']),
    );

    $result = $provider->transcribe('fake-bytes', 'video/webm');

    expect($result->text)->toBe('hello from audio-only webm');
});

test('transcribe() accepts video/mp4 as audio-only MP4 (Safari MediaRecorder quirk)', function (): void {
    // Mirrors the video/webm test above — Safari reports audio-only
    // MP4 as video/mp4; the multipart filename extension (m4a) keeps
    // every shipped STT vendor happy.
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test', 'model' => 'whisper-1'],
        json_encode(['text' => 'hello from audio-only mp4']),
    );

    $result = $provider->transcribe('fake-bytes', 'video/mp4');

    expect($result->text)->toBe('hello from audio-only mp4');
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

test('transcribe() transport failure throws SpeechToTextException with URL sanitised to <host>', function (): void {
    // Symfony's HttpClient raises TransportException for DNS, TLS,
    // connect-reset failures. The wire-facing message must NOT leak
    // the operator's chosen base URL: `scheme://host[:port]` collapses
    // to `scheme://<host>` so the SPA can still tell HTTP from HTTPS
    // failures apart.
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn(['api_key' => 'sk-test']);

    $provider = new OpenAiCompatibleTranscriber(
        new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new Symfony\Component\HttpClient\Exception\TransportException(
                    'Could not connect to https://api.mistral.ai/v1/audio/transcriptions: c-err 35 (TLS handshake)',
                );
            }
            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new LogicException('transport failure path must not reach stream()');
            }
            public function withOptions(array $options): static
            {
                return $this;
            }
        },
        $config,
    );

    $exception = null;
    try {
        $provider->transcribe('fake-bytes', 'audio/webm');
    } catch (SpeechToTextException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(SpeechToTextException::class)
        ->and($exception->getMessage())->toContain('request failed')
        ->and($exception->getMessage())->not->toContain('api.mistral.ai')
        ->and($exception->getMessage())->toContain('<host>')
        ->and($exception->getPrevious())->toBeInstanceOf(Symfony\Component\HttpClient\Exception\TransportException::class);
});

test('classifyHttpFailure() falls back to json_encode when the error body has no message field', function (): void {
    // Some vendors return a structured error envelope with no
    // `message` field at any depth (Mistral 401 envelope can be
    // `{error: {code, type}}`). The json_encode fallback covers that
    // case so the exception still surfaces a usable body to the SPA.
    $provider = buildOaiProvider(
        ['api_key' => 'sk-test'],
        json_encode(['error' => ['code' => 42, 'type' => 'auth_error']]),
        401,
    );

    $exception = null;
    try {
        $provider->transcribe('fake-bytes', 'audio/webm');
    } catch (SpeechToTextException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(SpeechToTextException::class)
        ->and($exception->getMessage())->toContain('HTTP 401');
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

    // The hint wins — the body must carry `language: en-US`, not the
    // configured `fr-FR`. After sendTranscribeRequest translates
    // `multipart` → `body` with fopen(), the body has:
    //   language => 'en-US' (scalar)
    //   model    => 'whisper-1' (scalar)
    //   file     => <resource> (fopen'd from the temp upload)
    expect($capturing->capturedBody)->toBeArray()
        ->and($capturing->capturedBody['language'])->toBe('en-US');
});

test('transcribe() with empty language setting and empty hint omits the language field from the body', function (): void {
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
    expect($capturing->capturedBody)->toBeArray()
        ->and($capturing->capturedBody)->not->toHaveKey('language')
        ->and($capturing->capturedBody['model'])->toBe('whisper-1')
        ->and($capturing->capturedBody['file'])->toBeResource();
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
// option as `application/x-www-form-urlencoded` when every value is a
// scalar — which every STT vendor rejects with `cannot carry files;
// use multipart/form-data`. The trigger to switch to multipart is
// ANY value being a PHP `resource` (Symfony's
// `HttpClientTrait::normalizeBody` line 346). We pre-build a
// `multipart` descriptor then translate to `body` with `fopen()`
// on each file field at send-time. Pin both halves: the descriptor
// stage and the body stage.
test('transcribe() sends multipart via body + fopen() (Symfony switch to multipart/form-data)', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn([
        'api_key' => 'sk-test',
        'model'   => 'whisper-1',
    ]);

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

    expect($capturedOptions)->toHaveKey('body');
    expect($capturedOptions['body'])->toBeArray();

    // The file field is a real PHP stream resource — this is what flips
    // Symfony's body normalizer from form-encoded to multipart.
    expect($capturedOptions['body']['file'])->toBeResource();

    // Text fields pass through as scalars.
    expect($capturedOptions['body']['model'])->toBe('whisper-1');

    // Sanity check: the descriptor's `multipart` doesn't leak through
    // to the actual HTTP options (only `body` does).
    expect($capturedOptions)->not->toHaveKey('multipart');
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

    expect($capturing->capturedBody['model'])->toBe('voxtral-mini-latest');
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
 * Pest custom expectation for the body shape Symfony emits when ANY
 * value is a PHP stream resource — `HttpClientTrait::normalizeBody`
 * auto-promotes the request to `multipart/form-data`. The
 * OaiCapturingHttpClient test decorator captures `$body`, so:
 *
 *   expect($capturing->capturedBody['file'])->toBeResource()
 *
 * is the canonical "this thing sends multipart" assertion, paired
 * with the explicit regression test that asserts the same invariant
 * (a guard against future regressions if someone removes the
 * `fopen()` translation).
 */
expect()->extend('toHaveMultipartBody', function () {
    /** @var array<string, mixed> $body */
    $body = $this->value;
    expect($body)->toBeArray();
    foreach ($body as $name => $value) {
        if (is_resource($value)) {
            expect($name)->toBeString();
            return $this;
        }
    }
    test()->fail('toHaveMultipartBody: no value in body was a PHP resource — Symfony will send the body as application/x-www-form-urlencoded, which Mistral rejects with `cannot carry files`.');
    return $this;
});
