<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Helpers

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

// Provider / model metadata

test('getProviderName returns anthropic_compatible', function (): void {
    $driver = makeAnthropicDriver(new MockHttpClient());
    expect($driver->getProviderName())->toBe('anthropic_compatible');
});

test('getModelName returns the model passed at construction', function (): void {
    $driver = makeAnthropicDriver(new MockHttpClient());
    expect($driver->getModelName())->toBe('claude-3-5-sonnet-20241022');
});

// Text response path

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

// Tool use response path

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

// Message conversion — tool result batching

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

// Message conversion — assistant tool_calls → Anthropic content blocks

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

// Tool definition conversion

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

    expect($capturedBody['system'])->toBeArray();
    expect($capturedBody['system'][0])->toMatchArray([
        'type' => 'text',
        'text' => 'You are helpful.',
    ]);
    expect($capturedBody['system'][0]['cache_control'])->toBe(['type' => 'ephemeral']);

    expect($capturedBody['tools'])->toHaveCount(1);
    expect($capturedBody['tools'][0])->toMatchArray([
        'name' => 'my_tool',
        'description' => 'Does something',
        'input_schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
        'cache_control' => ['type' => 'ephemeral'],
    ]);
});

// Error handling

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

test('complete throws LLMRetryableException on HTTP 500', function (): void {
    $client = new MockHttpClient(new MockResponse('{"error":{"type":"api_error"}}', ['http_code' => 500]));
    $driver = makeAnthropicDriver($client);

    expect(fn() => $driver->complete(makeAnthropicRequest()))->toThrow(LLMRetryableException::class);
});

// Temperature is never sent; the effort level replaces it

test('the temperature never reaches the Anthropic body', function (): void {
    $capturedBody = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'          => 'msg_4',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'ok']],
            'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = makeAnthropicDriver($client);
    $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
        temperature: 0.3,
    ));

    expect($capturedBody)->not->toHaveKey('temperature');
});

test('skips tool_use blocks in stop_reason=tool_use that are not actually tool_use', function (): void {
    // When stop_reason=tool_use but content blocks are mixed (e.g. text), the text
    // blocks must be skipped when extracting tool calls.
    $payload = json_encode([
        'id'          => 'msg_mixed',
        'stop_reason' => 'tool_use',
        'content'     => [
            ['type' => 'text', 'text' => 'Some thinking'],
            ['type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'do_thing', 'input' => []],
        ],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeAnthropicDriver($client);

    $response = $driver->complete(makeAnthropicRequest());

    expect($response->toolCalls)->toHaveCount(1);
    expect($response->toolCalls[0]->providerCallId)->toBe('toolu_x');
});

// Message conversion: tool-call flush boundaries, list arguments, plain user/assistant messages

test('plain user message without tool_calls is forwarded as role+content', function (): void {
    $capturedBody = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'msg_5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = makeAnthropicDriver($client);
    $request = makeAnthropicRequest([
        ['role' => 'user', 'content' => 'hello world'],
    ]);

    $driver->complete($request);

    expect($capturedBody['messages'])->toHaveCount(1);
    expect($capturedBody['messages'][0])->toBe(['role' => 'user', 'content' => 'hello world']);
});

test('trailing tool results are flushed even if no non-tool message follows', function (): void {
    $capturedBody = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'msg_6', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = makeAnthropicDriver($client);
    $request = makeAnthropicRequest([
        ['role' => 'user', 'content' => 'first'],
        ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'foo', 'content' => 'r1'],
        // No non-tool message after the tool result — must still flush
        ['role' => 'tool', 'tool_call_id' => 'c2', 'name' => 'bar', 'content' => 'r2'],
    ]);

    $driver->complete($request);

    expect($capturedBody['messages'])->toHaveCount(2);
    // The trailing flush becomes the second user message with both tool results
    expect($capturedBody['messages'][1]['role'])->toBe('user');
    expect($capturedBody['messages'][1]['content'])->toHaveCount(2);
});

test('non-function tool definitions are skipped', function (): void {
    $capturedBody = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'msg_7', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = makeAnthropicDriver($client);
    $request = makeAnthropicRequest(
        messages: [],
        tools: [
            ['type' => 'not_function', 'function' => ['name' => 'skip_me']],
            ['type' => 'function', 'function' => [
                'name' => 'keep_me',
                'description' => 'kept',
                'parameters' => ['type' => 'object', 'properties' => []],
            ]],
        ],
    );

    $driver->complete($request);

    expect($capturedBody['tools'])->toHaveCount(1);
    expect($capturedBody['tools'][0]['name'])->toBe('keep_me');
});

test('list-shaped assistant tool_call arguments are wrapped as object', function (): void {
    $rawBody = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$rawBody): MockResponse {
        $rawBody = $options['body'];

        return new MockResponse(json_encode([
            'id' => 'msg_8', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = makeAnthropicDriver($client);
    $request = makeAnthropicRequest([
        [
            'role'       => 'assistant',
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_list',
                'type'     => 'function',
                'function' => ['name' => 'list_tool', 'arguments' => '["a","b","c"]'],
            ]],
        ],
    ]);

    $driver->complete($request);

    // List arguments must be sent as object — Anthropic rejects bare arrays.
    // The (object) cast turns ["a","b","c"] into {"0":"a","1":"b","2":"c"} in JSON.
    expect($rawBody)->not->toContain('"input":["a","b","c"]');
    expect($rawBody)->toContain('"input":{"0":"a","1":"b","2":"c"}');
});

// Headers & URL — exercised via the new buildAnthropicHeaders() helper

test('x-api-key header is omitted when apiKey is empty (local models)', function (): void {
    $capturedHeaders = null;

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
        $capturedHeaders = $options['headers'];

        return new MockResponse(json_encode([
            'id' => 'msg_no_key', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = new AnthropicCompatibleDriver(
        apiKey: '',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com/v1/messages',
        httpClient: $client,
    );

    $driver->complete(makeAnthropicRequest());

    // The x-api-key header must NOT be present for local/Ollama models
    expect($capturedHeaders)->not->toHaveKey('x-api-key');
    // The version and content-type must still be there (Symfony flattens headers into a list of "Name: Value" strings)
    $flat = implode("\n", $capturedHeaders);
    expect($flat)->toContain('anthropic-version: 2026-10-21')
        ->and($flat)->toContain('Content-Type: application/json');
});

test('trailing slash in base_url is stripped before appending /v1/messages', function (): void {
    $capturedUrl = null;

    $client = new MockHttpClient(static function (string $method, string $url) use (&$capturedUrl): MockResponse {
        $capturedUrl = $url;

        return new MockResponse(json_encode([
            'id' => 'msg_url', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = new AnthropicCompatibleDriver(
        apiKey: 'k',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com/', // note trailing slash
        httpClient: $client,
    );

    $driver->complete(makeAnthropicRequest());

    expect($capturedUrl)->toBe('https://api.anthropic.com/v1/messages');
});

// Response parser — exercised via the new parseAnthropicResponse() helper

test('complete extracts reasoning from thinking content blocks', function (): void {
    $payload = json_encode([
        'id'          => 'msg_thinking',
        'stop_reason' => 'end_turn',
        'content'     => [
            ['type' => 'thinking', 'thinking' => 'I should consider X and Y.'],
            ['type' => 'text', 'text' => 'The answer is 42.'],
        ],
        'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeAnthropicDriver($client);

    $response = $driver->complete(makeAnthropicRequest());

    expect($response->content)->toBe('The answer is 42.')
        ->and($response->toolCalls)->toBeEmpty();
    expect($response->contentBlocks)->toHaveCount(2);
    expect($response->contentBlocks[0])->toBeInstanceOf(Spora\Drivers\ValueObjects\ContentBlock::class);
    expect($response->contentBlocks[0]->type)->toBe(Spora\Drivers\ValueObjects\ContentBlock::TYPE_THINKING);
    expect($response->contentBlocks[0]->text)->toBe('I should consider X and Y.');
    expect($response->contentBlocks[1]->type)->toBe(Spora\Drivers\ValueObjects\ContentBlock::TYPE_TEXT);
    expect($response->contentBlocks[1]->text)->toBe('The answer is 42.');
});

test('complete falls back to empty content for tool_use with no text', function (): void {
    $payload = json_encode([
        'id'          => 'msg_tool_only',
        'stop_reason' => 'tool_use',
        'content'     => [
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'do_x', 'input' => ['k' => 'v']],
        ],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]);

    $client = new MockHttpClient(new MockResponse($payload, ['http_code' => 200]));
    $driver = makeAnthropicDriver($client);

    $response = $driver->complete(makeAnthropicRequest());

    // No text content but tool_use stop_reason => content is null
    expect($response->content)->toBeNull()
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->toolName)->toBe('do_x');
});

// Reasoning effort

/**
 * Send a request through the driver and return the body that reached
 * the wire.
 *
 * @return array<string, mixed>
 */
function captureAnthropicBody(string $model, LLMRequest $request): array
{
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'          => 'msg_effort',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'ok']],
            'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    (new AnthropicCompatibleDriver(
        apiKey: 'test-key',
        model: $model,
        baseUrl: 'https://api.anthropic.com',
        httpClient: $client,
    ))->complete($request);

    return $captured;
}

function makeAnthropicEffortRequest(?string $effort): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: [],
        effort: $effort,
    );
}

test('a resolved effort level reaches the wire as output_config effort', function (): void {
    $body = captureAnthropicBody('claude-sonnet-5-5', makeAnthropicEffortRequest('high'));

    expect($body['output_config'])->toBe(['effort' => 'high']);
});

test('no effort level means no output_config on the wire', function (): void {
    $body = captureAnthropicBody('claude-sonnet-5-5', makeAnthropicEffortRequest(null));

    expect($body)->not->toHaveKey('output_config');
});

test('an effort level on an adaptive-capable model turns on adaptive thinking with a summarized display', function (): void {
    $body = captureAnthropicBody('claude-sonnet-5-5', makeAnthropicEffortRequest('medium'));

    expect($body['thinking'])->toBe(['type' => 'adaptive', 'display' => 'summarized']);
});

test('no effort level sends no thinking field', function (): void {
    $body = captureAnthropicBody('claude-sonnet-5-5', makeAnthropicEffortRequest(null));

    expect($body)->not->toHaveKey('thinking');
});

test('a model below 4.6 sends no thinking field even with an effort level', function (): void {
    $body = captureAnthropicBody('claude-opus-4-5-20251101', makeAnthropicEffortRequest('medium'));

    expect($body)->toHaveKey('output_config')
        ->and($body)->not->toHaveKey('thinking');
});

test('a model without an effort sends no output_config even with an effort level', function (): void {
    $body = captureAnthropicBody('claude-3-5-sonnet-20241022', makeAnthropicEffortRequest('medium'));

    expect($body)->not->toHaveKey('output_config')
        ->and($body)->not->toHaveKey('thinking');
});

// The exact body shape

test('the body without an effort level carries exactly the documented keys', function (): void {
    $body = captureAnthropicBody('claude-sonnet-5-5', makeAnthropicEffortRequest(null));

    expect(array_keys($body))->toBe(['model', 'system', 'messages', 'max_tokens']);
});

test('the body with an effort level carries exactly the documented keys', function (): void {
    $body = captureAnthropicBody('claude-sonnet-5-5', makeAnthropicEffortRequest('high'));

    expect(array_keys($body))->toBe(['model', 'system', 'messages', 'max_tokens', 'output_config', 'thinking']);
});

// Image input

test('the current Claude generations accept image input', function (): void {
    foreach (['claude-sonnet-5-5', 'claude-opus-4-6', 'claude-haiku-5-5', 'claude-mythos-preview'] as $model) {
        $driver = new AnthropicCompatibleDriver(
            apiKey: 'test-key',
            model: $model,
            baseUrl: 'https://api.anthropic.com',
            httpClient: new MockHttpClient(),
        );

        expect($driver->supportsImageInput())->toBeTrue();
    }
});

test('Claude 2 and the Instant models do not accept image input', function (): void {
    foreach (['claude-2.1', 'claude-instant-1.2'] as $model) {
        $driver = new AnthropicCompatibleDriver(
            apiKey: 'test-key',
            model: $model,
            baseUrl: 'https://api.anthropic.com',
            httpClient: new MockHttpClient(),
        );

        expect($driver->supportsImageInput())->toBeFalse();
    }
});

// The settings blob

test('the driver options are read from the settings blob', function (): void {
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'          => 'msg_settings',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'ok']],
            'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = AnthropicCompatibleDriver::makeFromSettings([
        'apiKey' => 'test-key',
        'model' => 'claude-sonnet-5-5',
        'baseUrl' => 'https://api.anthropic.com',
        'httpClient' => $client,
    ], ['enable_prompt_caching' => 'false']);

    $driver->complete(makeAnthropicRequest());

    expect($captured['system'])->toBe('You are helpful.');
});

test('prompt caching is on when the settings blob says nothing about it', function (): void {
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'          => 'msg_settings',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'ok']],
            'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = AnthropicCompatibleDriver::makeFromSettings([
        'apiKey' => 'test-key',
        'model' => 'claude-sonnet-5-5',
        'baseUrl' => 'https://api.anthropic.com',
        'httpClient' => $client,
    ], []);

    $driver->complete(makeAnthropicRequest());

    expect($captured['system'])->toBeArray();
});
