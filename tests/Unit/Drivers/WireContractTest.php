<?php

declare(strict_types=1);

use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The wire contract, one row per driver: the exact set of top-level
 * body keys the driver sends.
 *
 * A JSON-Schema validation would not catch the mistakes this table
 * exists for — the request bodies do not set `additionalProperties:
 * false`, so a schema validator accepts `max_tokens` on `/responses`
 * and a flat `reasoning_effort` there. Pinning the key set catches the
 * extra field and the missing one.
 *
 * The other drivers' rows live in the same table; this file carries the
 * Responses row.
 */
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

function wireContractRequest(?string $effort): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: [[
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => 'Searches the web.',
                'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
            ],
        ]],
        effort: $effort,
    );
}

test('the Responses body carries exactly the documented keys', function (): void {
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
