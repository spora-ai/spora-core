<?php

declare(strict_types=1);

/**
 * The wire contract, one row per driver and per mode: the exact set of
 * top-level body keys the driver sends.
 *
 * A JSON-Schema validation would not catch the mistakes this table
 * exists for — the request bodies do not set `additionalProperties:
 * false`, so a schema validator accepts `max_tokens` on `/responses`
 * and a flat `reasoning_effort` there. Pinning the key set catches the
 * extra field and the missing one.
 *
 * A field that has to be absent is as much a part of the contract as one
 * that has to be there, so every row is read in both of its states.
 */

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\EffortLevel;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @return list<array{type: "function", function: array{name: string, description: string, parameters: array<string, mixed>}}>
 */
function wireContractTools(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'web_search',
            'description' => 'Searches the web.',
            'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
        ],
    ]];
}

function wireContractRequest(?string $effort, ?array $tools = null): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: $tools ?? wireContractTools(),
        effort: $effort,
    );
}

function wireContractBody(LLMRequest $request): array
{
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured['body'] = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'resp_wire',
            'status' => 'completed',
            'output' => [],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    (new OpenAIResponsesDriver(
        apiKey: 'test',
        model: 'gpt-5.5',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
    ))->complete($request);

    return $captured['body'];
}

function wireContractChatBody(string $model, LLMRequest $request): array
{
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'chatcmpl_wire',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    (new OpenAICompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
    ))->complete($request);

    return $captured;
}

function wireContractAnthropicBody(string $model, LLMRequest $request): array
{
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'msg_wire',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    (new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.anthropic.com',
        httpClient: $client,
    ))->complete($request);

    return $captured;
}

test('the Responses body with tools carries exactly the documented keys', function (): void {
    $body = wireContractBody(wireContractRequest(null));

    expect(array_keys($body))->toEqualCanonicalizing([
        'model',
        'instructions',
        'input',
        'tools',
        'tool_choice',
        'max_output_tokens',
        'store',
    ]);
});

test('the Responses body with an effort carries the reasoning key as well', function (): void {
    $body = wireContractBody(wireContractRequest('high'));

    expect(array_keys($body))->toEqualCanonicalizing([
        'model',
        'instructions',
        'input',
        'tools',
        'tool_choice',
        'max_output_tokens',
        'store',
        'reasoning',
    ]);
});

test('the Responses body without tools carries neither the tools nor the tool choice', function (): void {
    $body = wireContractBody(wireContractRequest(null, []));

    expect(array_keys($body))->toEqualCanonicalizing([
        'model',
        'instructions',
        'input',
        'max_output_tokens',
        'store',
    ]);
});

test('the Responses body without tools and with an effort carries the reasoning key', function (): void {
    $body = wireContractBody(wireContractRequest('high', []));

    expect(array_keys($body))->toEqualCanonicalizing([
        'model',
        'instructions',
        'input',
        'max_output_tokens',
        'store',
        'reasoning',
    ]);
});

test('the Responses body never carries the Chat Completions keys', function (): void {
    $body = wireContractBody(wireContractRequest('high'));

    expect($body)->not->toHaveKey('messages')
        ->and($body)->not->toHaveKey('max_tokens')
        ->and($body)->not->toHaveKey('max_completion_tokens')
        ->and($body)->not->toHaveKey('temperature')
        ->and($body)->not->toHaveKey('reasoning_effort')
        ->and($body)->not->toHaveKey('response_format')
        ->and($body)->not->toHaveKey('system');
});

test('the Chat Completions body of a non-reasoning model carries exactly the documented keys', function (): void {
    $withTools = wireContractChatBody('gpt-4o', wireContractRequest(null));
    $withoutTools = wireContractChatBody('gpt-4o', wireContractRequest(null, []));

    expect(array_keys($withTools))->toEqualCanonicalizing([
        'model',
        'messages',
        'max_tokens',
        'temperature',
        'tools',
        'tool_choice',
    ])
        ->and(array_keys($withoutTools))->toEqualCanonicalizing([
            'model',
            'messages',
            'max_tokens',
            'temperature',
        ]);
});

test('the Chat Completions body of a non-reasoning model with an effort carries exactly the documented keys', function (): void {
    $withTools = wireContractChatBody('gpt-4o', wireContractRequest(EffortLevel::High->value));
    $withoutTools = wireContractChatBody('gpt-4o', wireContractRequest(EffortLevel::High->value, []));

    expect(array_keys($withTools))->toEqualCanonicalizing([
        'model',
        'messages',
        'max_tokens',
        'temperature',
        'tools',
        'tool_choice',
        'reasoning_effort',
    ])
        ->and(array_keys($withoutTools))->toEqualCanonicalizing([
            'model',
            'messages',
            'max_tokens',
            'temperature',
            'reasoning_effort',
        ])
        ->and($withTools['reasoning_effort'])->toBe('high')
        ->and($withoutTools['reasoning_effort'])->toBe('high');
});

test('the Chat Completions body of a reasoning model carries exactly the documented keys', function (): void {
    $withTools = wireContractChatBody('gpt-5.5', wireContractRequest(EffortLevel::High->value));
    $withoutTools = wireContractChatBody('gpt-5.5', wireContractRequest(EffortLevel::High->value, []));

    expect(array_keys($withTools))->toEqualCanonicalizing([
        'model',
        'messages',
        'max_completion_tokens',
        'tools',
        'tool_choice',
        'reasoning_effort',
    ])
        ->and(array_keys($withoutTools))->toEqualCanonicalizing([
            'model',
            'messages',
            'max_completion_tokens',
            'reasoning_effort',
        ])
        ->and($withTools['reasoning_effort'])->toBe('high')
        ->and($withoutTools['reasoning_effort'])->toBe('high');

    // From GPT-5.4 on, Chat Completions rejects tools at any effort other
    // than `none`, and from GPT-5.5 on the model's own default is `medium`,
    // so silence is a 400 too. Without the tools there is nothing to
    // reject, so nothing is sent and the model decides.
    $toolsWithoutEffort = wireContractChatBody('gpt-5.5', wireContractRequest(null));
    $noToolsWithoutEffort = wireContractChatBody('gpt-5.5', wireContractRequest(null, []));

    expect(array_keys($toolsWithoutEffort))->toEqualCanonicalizing([
        'model',
        'messages',
        'max_completion_tokens',
        'tools',
        'tool_choice',
        'reasoning_effort',
    ])
        ->and($toolsWithoutEffort['reasoning_effort'])->toBe('none')
        ->and(array_keys($noToolsWithoutEffort))->toEqualCanonicalizing([
            'model',
            'messages',
            'max_completion_tokens',
        ]);
});

test('the Anthropic body without an effort carries exactly the documented keys', function (): void {
    // The model here has adaptive thinking, so the missing `thinking` key
    // is the missing effort and not the model.
    $withTools = wireContractAnthropicBody('claude-sonnet-4-6', wireContractRequest(null));
    $withoutTools = wireContractAnthropicBody('claude-sonnet-4-6', wireContractRequest(null, []));

    expect(array_keys($withTools))->toEqualCanonicalizing([
        'model',
        'system',
        'messages',
        'max_tokens',
        'tools',
    ])
        ->and(array_keys($withoutTools))->toEqualCanonicalizing([
            'model',
            'system',
            'messages',
            'max_tokens',
        ]);
});

test('the Anthropic body with an effort carries exactly the documented keys', function (): void {
    $withTools = wireContractAnthropicBody('claude-sonnet-4-6', wireContractRequest(EffortLevel::High->value));
    $withoutTools = wireContractAnthropicBody('claude-sonnet-4-6', wireContractRequest(EffortLevel::High->value, []));

    expect(array_keys($withTools))->toEqualCanonicalizing([
        'model',
        'system',
        'messages',
        'max_tokens',
        'tools',
        'output_config',
        'thinking',
    ])
        ->and(array_keys($withoutTools))->toEqualCanonicalizing([
            'model',
            'system',
            'messages',
            'max_tokens',
            'output_config',
            'thinking',
        ])
        ->and($withTools['output_config'])->toBe(['effort' => 'high'])
        ->and($withoutTools['output_config'])->toBe(['effort' => 'high']);

    // Adaptive thinking arrived with the 4.6 generation, so the older
    // model carries the effort without the thinking key.
    $withoutAdaptiveThinking = wireContractAnthropicBody('claude-sonnet-4-5-20250929', wireContractRequest(EffortLevel::High->value));

    expect(array_keys($withoutAdaptiveThinking))->toEqualCanonicalizing([
        'model',
        'system',
        'messages',
        'max_tokens',
        'tools',
        'output_config',
    ])
        ->and($withoutAdaptiveThinking['output_config'])->toBe(['effort' => 'high']);
});
