<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeAnthropicDriver(MockHttpClient $client): AnthropicCompatibleDriver
{
    return new AnthropicCompatibleDriver(
        apiKey: 'test-anthropic-key',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com/v1/messages',
        httpClient: $client,
    );
}

function makeAnthropicRequest(array $messages = [], array $tools = []): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: $messages,
        tools: $tools,
    );
}

// ---------------------------------------------------------------------------
// Provider / model metadata
// ---------------------------------------------------------------------------

test('getProviderName returns anthropic_compatible', function (): void {
    $driver = makeAnthropicDriver(new MockHttpClient());
    expect($driver->getProviderName())->toBe('anthropic_compatible');
});

test('getModelName returns the model passed at construction', function (): void {
    $driver = makeAnthropicDriver(new MockHttpClient());
    expect($driver->getModelName())->toBe('claude-3-5-sonnet-20241022');
});

// ---------------------------------------------------------------------------
// Text response path
// ---------------------------------------------------------------------------

test('complete returns text content when stop_reason is end_turn', function (): void {
    $payload = json_encode([
        'id'          => 'msg_abc',
        'stop_reason' => 'end_turn',
        'content'     => [['type' => 'text', 'text' => 'Hi there!']],
        'usage'       => ['input_tokens' => 15, 'output_tokens' => 3],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeAnthropicDriver($client);

    $response = $driver->complete(makeAnthropicRequest());

    expect($response->content)->toBe('Hi there!')
        ->and($response->toolCalls)->toBeEmpty()
        ->and($response->inputTokens)->toBe(15)
        ->and($response->outputTokens)->toBe(3)
        ->and($response->completionId)->toBe('msg_abc')
        ->and($response->hasToolCalls())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Tool use response path
// ---------------------------------------------------------------------------

test('complete returns tool calls when stop_reason is tool_use', function (): void {
    $payload = json_encode([
        'id'          => 'msg_xyz',
        'stop_reason' => 'tool_use',
        'content'     => [
            [
                'type'  => 'tool_use',
                'id'    => 'toolu_01',
                'name'  => 'send_email',
                'input' => ['to' => 'test@example.com', 'subject' => 'Hello'],
            ],
        ],
        'usage' => ['input_tokens' => 25, 'output_tokens' => 12],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeAnthropicDriver($client);

    $response = $driver->complete(makeAnthropicRequest());

    expect($response->hasToolCalls())->toBeTrue()
        ->and($response->content)->toBeNull()
        ->and($response->toolCalls)->toHaveCount(1);

    $tc = $response->toolCalls[0];
    expect($tc->providerCallId)->toBe('toolu_01')
        ->and($tc->toolName)->toBe('send_email')
        ->and($tc->arguments)->toBe(['to' => 'test@example.com', 'subject' => 'Hello']);
});

test('complete handles multiple parallel tool calls', function (): void {
    $payload = json_encode([
        'id'          => 'msg_parallel',
        'stop_reason' => 'tool_use',
        'content'     => [
            ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'tool_a', 'input' => []],
            ['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'tool_b', 'input' => ['x' => 99]],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeAnthropicDriver($client);

    $response = $driver->complete(makeAnthropicRequest());

    expect($response->toolCalls)->toHaveCount(2)
        ->and($response->toolCalls[0]->providerCallId)->toBe('toolu_a')
        ->and($response->toolCalls[1]->toolName)->toBe('tool_b')
        ->and($response->toolCalls[1]->arguments)->toBe(['x' => 99]);
});

// ---------------------------------------------------------------------------
// Message conversion — tool result batching
// ---------------------------------------------------------------------------

test('consecutive tool result messages are batched into one user turn', function (): void {
    $capturedBody = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'          => 'msg_1',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'done']],
            'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver  = makeAnthropicDriver($client);
    $request = makeAnthropicRequest([
        // Two tool results from the same turn must merge into ONE user message
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'foo', 'content' => 'result_1'],
        ['role' => 'tool', 'tool_call_id' => 'call_2', 'name' => 'bar', 'content' => 'result_2'],
    ]);

    $driver->complete($request);

    $messages = $capturedBody['messages'];
    // Both tool results must land in a single user message with two tool_result blocks
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toHaveCount(2);
    expect($messages[0]['content'][0])->toBe([
        'type'        => 'tool_result',
        'tool_use_id' => 'call_1',
        'content'     => 'result_1',
    ]);
});

// ---------------------------------------------------------------------------
// Message conversion — assistant tool_calls → Anthropic content blocks
// ---------------------------------------------------------------------------

test('assistant message with tool_calls is converted to Anthropic tool_use content blocks', function (): void {
    $capturedBody = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'          => 'msg_2',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'ok']],
            'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver  = makeAnthropicDriver($client);
    $request = makeAnthropicRequest([
        [
            'role'       => 'assistant',
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_abc',
                'type'     => 'function',
                'function' => ['name' => 'do_thing', 'arguments' => '{"val":42}'],
            ]],
        ],
    ]);

    $driver->complete($request);

    $messages = $capturedBody['messages'];
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('assistant');
    expect($messages[0]['content'][0])->toBe([
        'type'  => 'tool_use',
        'id'    => 'call_abc',
        'name'  => 'do_thing',
        'input' => ['val' => 42],
    ]);
});

// ---------------------------------------------------------------------------
// Tool definition conversion
// ---------------------------------------------------------------------------

test('OpenAI-format tools are converted to Anthropic input_schema format', function (): void {
    $capturedBody = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'          => 'msg_3',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'ok']],
            'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver  = makeAnthropicDriver($client);
    $request = makeAnthropicRequest(
        messages: [],
        tools: [[
            'type'     => 'function',
            'function' => [
                'name'        => 'my_tool',
                'description' => 'Does something',
                'parameters'  => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            ],
        ]],
    );

    $driver->complete($request);

    expect($capturedBody['tools'])->toHaveCount(1);
    expect($capturedBody['tools'][0])->toBe([
        'name'         => 'my_tool',
        'description'  => 'Does something',
        'input_schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
    ]);
});

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------

test('complete throws LLMRateLimitException on HTTP 429', function (): void {
    $client = new MockHttpClient(new MockResponse('{"error":{"type":"rate_limit_error"}}', ['http_code' => 429]));
    $driver = makeAnthropicDriver($client);

    expect(fn() => $driver->complete(makeAnthropicRequest()))->toThrow(LLMRateLimitException::class);
});

test('complete throws LLMProviderException on HTTP 401', function (): void {
    $client = new MockHttpClient(new MockResponse('{"error":{"type":"authentication_error"}}', ['http_code' => 401]));
    $driver = makeAnthropicDriver($client);

    expect(fn() => $driver->complete(makeAnthropicRequest()))->toThrow(LLMProviderException::class);
});

test('complete throws LLMProviderException on HTTP 500', function (): void {
    $client = new MockHttpClient(new MockResponse('{"error":{"type":"api_error"}}', ['http_code' => 500]));
    $driver = makeAnthropicDriver($client);

    expect(fn() => $driver->complete(makeAnthropicRequest()))->toThrow(LLMProviderException::class);
});
