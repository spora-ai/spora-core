<?php

declare(strict_types=1);

use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Helpers

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

/**
 * @param list<array<string, mixed>> $tools
 */
function makeToolRequest(array $tools, ?string $effort = null): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: $tools,
        effort: $effort,
    );
}

/**
 * @return list<array<string, mixed>>
 */
function openAITestTools(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'do_thing',
            'description' => 'Does a thing',
            'parameters' => ['type' => 'object', 'properties' => []],
        ],
    ]];
}

/**
 * Send a request through the driver and return the body that reached
 * the wire.
 *
 * @return array<string, mixed>
 */
function captureOpenAIBody(string $model, LLMRequest $request, array $effortLevels = []): array
{
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'      => 'cmp',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    (new OpenAICompatibleDriver(
        apiKey: 'test-key',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
        effortLevels: $effortLevels,
    ))->complete($request);

    return $captured;
}

// Provider / model metadata

test('getProviderName returns openai_compatible', function (): void {
    $driver = makeOpenAIDriver(new MockHttpClient());
    expect($driver->getProviderName())->toBe('openai_compatible');
});

test('getModelName returns the model passed at construction', function (): void {
    $driver = makeOpenAIDriver(new MockHttpClient());
    expect($driver->getModelName())->toBe('gpt-4o');
});

// Text response path

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

// Tool call response path

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

// Error handling

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

test('complete throws LLMRetryableException on HTTP 500', function (): void {
    $client = new MockHttpClient(new MockResponse('Internal Server Error', ['http_code' => 500]));
    $driver = makeOpenAIDriver($client);

    expect(fn() => $driver->complete(makeRequest()))->toThrow(LLMRetryableException::class);
});

// Request construction — system prompt prepended

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

test('a compacted task never places a role:summary message on the wire', function (): void {
    // Asserting on the captured body covers the full wire path.
    $userId = bootAuthLayer()->register('wire@example.com', 'Password1!', 'Wire');
    $config = Spora\Models\LLMDriverConfiguration::create([
        'principal_id'      => null,
        'name'              => 'Wire Test Config',
        'driver_class'      => OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);
    $agent = Spora\Models\Agent::create([
        'principal_id'        => createUserPrincipalPublic($userId),
        'name'                => 'Wire Test Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'           => 10,
        'is_active'           => true,
    ]);
    $task = Spora\Models\Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => (int) $agent->principal_id,
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'wire test',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    Spora\Models\TaskHistory::create(['task_id' => $task->id, 'sequence' => 0, 'role' => 'user', 'content' => 'Q1']);
    Spora\Models\TaskHistory::create(['task_id' => $task->id, 'sequence' => 1, 'role' => 'assistant', 'content' => 'A1']);
    Spora\Models\TaskHistory::create([
        'task_id'                   => $task->id,
        'sequence'                  => 2,
        'role'                      => 'summary',
        'content'                   => 'Compacted first two turns.',
        'summarized_sequence_range' => '0-1',
    ]);
    Spora\Models\TaskHistory::create(['task_id' => $task->id, 'sequence' => 3, 'role' => 'user', 'content' => 'Q3']);

    $capturedBody = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id'      => 'cmp',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    makeOpenAIDriver($client)->complete(new LLMRequest(
        systemPrompt: 'Be concise.',
        messages: (new Spora\Agents\MessageHistoryBuilder())->build($task->id),
        tools: [],
    ));

    expect(array_column($capturedBody['messages'], 'role'))->toBe(['system', 'user', 'user']);
});

// Timeout configuration

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

    expect((int) $capturedOptions['timeout'])->toBe(300);
});

// Reasoning effort

test('a resolved effort level reaches the wire as reasoning_effort', function (): void {
    $body = captureOpenAIBody('gpt-4o', new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
        effort: 'high',
    ));

    expect($body['reasoning_effort'])->toBe('high');
});

test('no effort level means no reasoning_effort on the wire', function (): void {
    $body = captureOpenAIBody('gpt-4o', makeRequest());

    expect($body)->not->toHaveKey('reasoning_effort');
});

// The reasoning-model wire shape

test('a reasoning model is sent max_completion_tokens and no temperature', function (): void {
    $body = captureOpenAIBody('gpt-5.5', makeRequest());

    expect($body)->toHaveKey('max_completion_tokens')
        ->and($body['max_completion_tokens'])->toBe(4096)
        ->and($body)->not->toHaveKey('max_tokens')
        ->and($body)->not->toHaveKey('temperature');
});

test('a non-reasoning model keeps max_tokens and the temperature', function (): void {
    $body = captureOpenAIBody('gpt-4o', makeRequest());

    expect($body)->toHaveKey('max_tokens')
        ->and($body['max_tokens'])->toBe(4096)
        ->and($body['temperature'])->toBe(0.7)
        ->and($body)->not->toHaveKey('max_completion_tokens');
});

test('a reasoning model is sent the developer role instead of the system role', function (): void {
    $body = captureOpenAIBody('gpt-5.5', makeRequest([['role' => 'user', 'content' => 'hi']]));

    expect($body['messages'][0]['role'])->toBe('developer');
});

test('a non-reasoning model keeps the system role', function (): void {
    $body = captureOpenAIBody('gpt-4o', makeRequest([['role' => 'user', 'content' => 'hi']]));

    expect($body['messages'][0]['role'])->toBe('system');
});

// Tools and the none effort

test('tools without a resolved effort send reasoning_effort none on a GPT-5.4 model', function (): void {
    $body = captureOpenAIBody('gpt-5.4', makeToolRequest(openAITestTools()));

    expect($body['reasoning_effort'])->toBe('none');
});

test('an explicit effort level is sent unchanged next to the tools', function (): void {
    $body = captureOpenAIBody('gpt-5.4', makeToolRequest(openAITestTools(), 'medium'));

    expect($body['reasoning_effort'])->toBe('medium');
});

test('the none escape hatch survives a level list that does not name none', function (): void {
    // The escape hatch is what the API requires, not a depth the operator
    // chose. A configuration naming its own levels without `none` would
    // otherwise leave the request with nothing the provider accepts.
    $body = captureOpenAIBody('gpt-5.5', makeToolRequest(openAITestTools()), ['low', 'medium', 'high']);

    expect($body['reasoning_effort'])->toBe('none');
});

test('no tools and no effort send no reasoning_effort', function (): void {
    $body = captureOpenAIBody('gpt-5.4', makeRequest());

    expect($body)->not->toHaveKey('reasoning_effort');
});

test('no tools and a resolved effort send that effort', function (): void {
    $body = captureOpenAIBody('gpt-5.4', new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [],
        tools: [],
        effort: 'low',
    ));

    expect($body['reasoning_effort'])->toBe('low');
});

test('the none escape hatch is not forced on a model that accepts tools at any effort', function (): void {
    $body = captureOpenAIBody('gpt-5.2', makeToolRequest(openAITestTools()));

    expect($body)->not->toHaveKey('reasoning_effort');
});

// The exact body shape

test('the body of a non-reasoning model carries exactly the documented keys', function (): void {
    $body = captureOpenAIBody('gpt-4o', makeRequest());

    expect(array_keys($body))->toBe(['model', 'messages', 'max_tokens', 'temperature']);
});

test('the body of a reasoning model carries exactly the documented keys', function (): void {
    $body = captureOpenAIBody('gpt-5.5', makeRequest());

    expect(array_keys($body))->toBe(['model', 'messages', 'max_completion_tokens']);
});

test('the body of a reasoning model with tools carries exactly the documented keys', function (): void {
    $body = captureOpenAIBody('gpt-5.5', makeToolRequest(openAITestTools(), 'high'));

    expect(array_keys($body))->toBe(['model', 'messages', 'max_completion_tokens', 'tools', 'tool_choice', 'reasoning_effort']);
});

// Image input

test('the current OpenAI generations accept image input', function (): void {
    foreach (['gpt-4.1', 'gpt-4.1-mini', 'gpt-5.5', 'gpt-5.4-mini', 'gpt-6.1-sol'] as $model) {
        $driver = new OpenAICompatibleDriver(
            apiKey: 'test-key',
            model: $model,
            baseUrl: 'https://api.openai.com/v1',
            httpClient: new MockHttpClient(),
        );

        expect($driver->supportsImageInput())->toBeTrue();
    }
});
