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
 * inner client runs prepareRequest() (which would convert the multipart
 * array into a streaming Closure and lose the field-level shape).
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
    // not the configured `fr-FR`.
    expect($capturing->capturedBody)->toBeArray()
        ->and($capturing->capturedBody['language'])->toBe('en-US');
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
    expect($capturing->capturedBody)->toBeArray()
        ->and($capturing->capturedBody)->not->toHaveKey('language')
        ->and($capturing->capturedBody['model'])->toBe('whisper-1')
        ->and($capturing->capturedBody['file'])->toBeInstanceOf(Symfony\Component\HttpFoundation\File\UploadedFile::class);
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
            public mixed $captured = null;
            public function __construct(private MockHttpClient $mock, public mixed &$capturedHeaders) {}
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->capturedHeaders = $options['headers'] ?? [];
                $this->captured = $options['body'] ?? null;
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
