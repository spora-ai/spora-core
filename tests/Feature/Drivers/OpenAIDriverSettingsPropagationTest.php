<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use ReflectionClass;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * B1 — OpenAI driver settings reach the wire body and headers.
 * One assertion per UI-editable setting so a future refactor that drops
 * a field is caught immediately.
 */
function openAITestRequest(?float $temperature = null, ?int $maxTokens = null): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: [],
        maxTokens: $maxTokens ?? 4096,
        temperature: $temperature ?? 0.7,
    );
}

function openAIMockResponse(): MockResponse
{
    return new MockResponse(json_encode([
        'id'       => 'chatcmpl-test',
        'choices'  => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        'usage'    => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
    ]), ['http_code' => 200]);
}

function captureOpenAIRequestBody(array &$captured): callable
{
    return static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured['url']    = $url;
        $captured['body']   = json_decode($options['body'], true);
        $captured['header'] = $options['headers'] ?? [];
        return openAIMockResponse();
    };
}

// api_key

test('OpenAI driver sets Authorization Bearer header from api_key setting', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'sk-test-1234',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest());

    $headers = $captured['header'];
    $auth = array_values(array_filter($headers, static fn($h) => str_starts_with($h, 'Authorization:')));
    expect($auth)->not->toBeEmpty();
    expect($auth[0])->toContain('Authorization: Bearer sk-test-1234');
});

test('OpenAI driver omits Authorization header when api_key is empty', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: '',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest());

    $headers = $captured['header'];
    $authHeader = array_filter($headers, static fn($h) => str_starts_with($h, 'Authorization:'));
    expect($authHeader)->toBeEmpty();
});

// base_url

test('OpenAI driver appends /chat/completions to base_url', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://my-proxy.example.com/v1/',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest());

    expect($captured['url'])->toBe('https://my-proxy.example.com/v1/chat/completions');
});

// model

test('OpenAI driver passes the configured model verbatim in body', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest());

    expect($captured['body']['model'])->toBe('gpt-4o-mini');
});

// temperature

test('OpenAI driver passes LLMRequest temperature into the request body', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest(temperature: 0.3));

    expect($captured['body']['temperature'])->toBe(0.3);
});

test('OpenAI driver passes 0.0 temperature verbatim (deterministic mode)', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest(temperature: 0.0));

    expect($captured['body']['temperature'])->toBe(0.0);
});

// max_tokens

test('OpenAI driver passes LLMRequest maxTokens into max_tokens body field', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest(maxTokens: 16384));

    expect($captured['body']['max_tokens'])->toBe(16384);
});

test('OpenAI driver passes large max_tokens (200000) without clamping', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest(maxTokens: 200_000));

    expect($captured['body']['max_tokens'])->toBe(200_000);
});

// timeout

test('OpenAI driver applies the configured timeout to the HTTP client', function (): void {
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(openAIMockResponse()),
        timeout: 42,
    );

    $reflection = new ReflectionClass($driver);
    $prop = $reflection->getProperty('timeout');
    $prop->setAccessible(true);

    expect($prop->getValue($driver))->toBe(42);
});

// supports_image_input

test('OpenAI driver constructor exposes supportsImageInput getter', function (): void {
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-3.5-turbo',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        supportsImageInput: false,
    );

    // Even though gpt-3.5-turbo is non-vision by the model heuristic, the
    // operator's explicit `false` wins — defensive against silent regression.
    expect($driver->supportsImageInput())->toBeFalse();
});

test('OpenAI driver constructor exposes supportsImageInput=true override on a non-vision model', function (): void {
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-3.5-turbo',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        supportsImageInput: true,
    );

    expect($driver->supportsImageInput())->toBeTrue();
});

// request shape

test('OpenAI driver includes system + messages + tools in body', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(new LLMRequest(
        systemPrompt: 'sys',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: [],
        maxTokens: 1024,
    ));

    expect($captured['body'])->toHaveKeys(['model', 'messages', 'max_tokens', 'temperature']);
    // OpenAI prepends system as the first message
    expect($captured['body']['messages'][0]['role'])->toBe('system');
    expect($captured['body']['messages'][0]['content'])->toBe('sys');
    expect($captured['body']['messages'][1]['role'])->toBe('user');
    expect($captured['body']['messages'][1]['content'])->toBe('hi');
});

test('OpenAI driver omits tools from body when none configured', function (): void {
    $captured = [];
    $driver = new OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(captureOpenAIRequestBody($captured)),
    );
    $driver->complete(openAITestRequest());

    expect($captured['body'])->not->toHaveKey('tools');
});
