<?php

declare(strict_types=1);

use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeOpenAIDriver(MockHttpClient $client): OpenAICompatibleDriver
{
    return new OpenAICompatibleDriver(
        apiKey: 'test-key',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
    );
}

function makeRequest(array $messages = []): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: $messages,
        tools: [],
    );
}

// ---------------------------------------------------------------------------
// Provider / model metadata
// ---------------------------------------------------------------------------

test('getProviderName returns openai_compatible', function (): void {
    $driver = makeOpenAIDriver(new MockHttpClient());
    expect($driver->getProviderName())->toBe('openai_compatible');
});

test('getModelName returns the model passed at construction', function (): void {
    $driver = makeOpenAIDriver(new MockHttpClient());
    expect($driver->getModelName())->toBe('gpt-4o');
});

// ---------------------------------------------------------------------------
// Text response path
// ---------------------------------------------------------------------------

test('complete returns LLMResponse with text content when finish_reason is stop', function (): void {
    $payload = json_encode([
        'id'      => 'chatcmpl-abc',
        'choices' => [[
            'finish_reason' => 'stop',
            'message'       => ['role' => 'assistant', 'content' => 'Hello!'],
        ]],
        'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeOpenAIDriver($client);

    $response = $driver->complete(makeRequest());

    expect($response->content)->toBe('Hello!')
        ->and($response->toolCalls)->toBeEmpty()
        ->and($response->inputTokens)->toBe(20)
        ->and($response->outputTokens)->toBe(5)
        ->and($response->completionId)->toBe('chatcmpl-abc')
        ->and($response->hasToolCalls())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Tool call response path
// ---------------------------------------------------------------------------

test('complete returns tool calls when finish_reason is tool_calls', function (): void {
    $payload = json_encode([
        'id'      => 'chatcmpl-xyz',
        'choices' => [[
            'finish_reason' => 'tool_calls',
            'message'       => [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => [
                    [
                        'id'       => 'call_001',
                        'type'     => 'function',
                        'function' => [
                            'name'      => 'send_email',
                            'arguments' => '{"to":"user@example.com","subject":"Hi"}',
                        ],
                    ],
                ],
            ],
        ]],
        'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeOpenAIDriver($client);

    $response = $driver->complete(makeRequest());

    expect($response->hasToolCalls())->toBeTrue()
        ->and($response->content)->toBeNull()
        ->and($response->toolCalls)->toHaveCount(1);

    $tc = $response->toolCalls[0];
    expect($tc->providerCallId)->toBe('call_001')
        ->and($tc->toolName)->toBe('send_email')
        ->and($tc->arguments)->toBe(['to' => 'user@example.com', 'subject' => 'Hi']);
});

test('complete handles multiple parallel tool calls', function (): void {
    $payload = json_encode([
        'id'      => 'chatcmpl-parallel',
        'choices' => [[
            'finish_reason' => 'tool_calls',
            'message'       => [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => [
                    ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'tool_a', 'arguments' => '{}']],
                    ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'tool_b', 'arguments' => '{"x":1}']],
                ],
            ],
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeOpenAIDriver($client);

    $response = $driver->complete(makeRequest());

    expect($response->toolCalls)->toHaveCount(2)
        ->and($response->toolCalls[0]->providerCallId)->toBe('call_a')
        ->and($response->toolCalls[1]->providerCallId)->toBe('call_b')
        ->and($response->toolCalls[1]->arguments)->toBe(['x' => 1]);
});

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------

test('complete throws LLMRateLimitException on HTTP 429', function (): void {
    $client = new MockHttpClient(new MockResponse('{"error":"rate limited"}', ['http_code' => 429]));
    $driver = makeOpenAIDriver($client);

    expect(fn() => $driver->complete(makeRequest()))->toThrow(LLMRateLimitException::class);
});

test('complete throws LLMProviderException on HTTP 401', function (): void {
    $client = new MockHttpClient(new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]));
    $driver = makeOpenAIDriver($client);

    expect(fn() => $driver->complete(makeRequest()))->toThrow(LLMProviderException::class);
});

test('complete throws LLMProviderException on HTTP 500', function (): void {
    $client = new MockHttpClient(new MockResponse('Internal Server Error', ['http_code' => 500]));
    $driver = makeOpenAIDriver($client);

    expect(fn() => $driver->complete(makeRequest()))->toThrow(LLMProviderException::class);
});

// ---------------------------------------------------------------------------
// Request construction — system prompt prepended
// ---------------------------------------------------------------------------

test('complete prepends the system prompt as the first message', function (): void {
    $capturedBody = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'      => 'cmp',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver  = makeOpenAIDriver($client);
    $request = new LLMRequest(
        systemPrompt: 'Be concise.',
        messages: [['role' => 'user', 'content' => 'Hello']],
        tools: [],
    );

    $driver->complete($request);

    expect($capturedBody['messages'][0])->toBe(['role' => 'system', 'content' => 'Be concise.'])
        ->and($capturedBody['messages'][1])->toBe(['role' => 'user', 'content' => 'Hello']);
});

// ---------------------------------------------------------------------------
// Timeout configuration
// ---------------------------------------------------------------------------

test('complete uses the timeout value passed at construction', function (): void {
    $capturedOptions = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
        $capturedOptions = $options;
        return new MockResponse(json_encode([
            'id'      => 'cmp',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = new OpenAICompatibleDriver(
        apiKey: 'test-key',
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
        timeout: 120,
    );

    $driver->complete(makeRequest());

    expect((int) $capturedOptions['timeout'])->toBe(120);
});

test('complete falls back to 45 seconds when no timeout is passed', function (): void {
    $capturedOptions = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
        $capturedOptions = $options;
        return new MockResponse(json_encode([
            'id'      => 'cmp',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = makeOpenAIDriver($client);

    $driver->complete(makeRequest());

    expect((int) $capturedOptions['timeout'])->toBe(45);
});
