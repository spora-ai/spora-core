<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `configuration_update` changes the effort without rewriting the
 * request-level value, so the cached prompt prefix survives. It is only
 * available on the models that support it; on an earlier model the same
 * change is a request-level parameter.
 */
function configurationUpdateBody(string $model, ?string $effort): array
{
    $captured = [];

    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured['body'] = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = new OpenAIResponsesDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
    );

    $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hi']],
        tools: [],
        effort: $effort,
    ));

    return $captured['body'];
}

test('a model that supports configuration_update carries the effort as an input item', function (): void {
    $body = configurationUpdateBody('gpt-6-astra', 'high');

    expect($body)->not->toHaveKey('reasoning');

    $update = end($body['input']);
    expect($update)->toBe([
        'type' => 'configuration_update',
        'reasoning' => ['effort' => 'high'],
    ]);
});

test('the configuration update is the last input item, so it applies to the response after it', function (): void {
    $body = configurationUpdateBody('gpt-6.1-sol', 'medium');

    $last = end($body['input']);
    $previous = $body['input'][count($body['input']) - 2];

    expect($last['type'])->toBe('configuration_update')
        ->and($previous['type'])->toBe('message');
});

test('a model without configuration_update carries the effort as a request-level reasoning object', function (): void {
    $body = configurationUpdateBody('gpt-5.5', 'high');

    expect($body['reasoning'])->toBe(['effort' => 'high']);

    foreach ($body['input'] as $item) {
        expect($item['type'])->not->toBe('configuration_update');
    }
});

test('no effort means neither a configuration update nor a reasoning object', function (): void {
    $body = configurationUpdateBody('gpt-6-astra', null);

    expect($body)->not->toHaveKey('reasoning');

    foreach ($body['input'] as $item) {
        expect($item['type'])->not->toBe('configuration_update');
    }
});

test('the tool-call restriction that forces Chat Completions to none does not apply here', function (): void {
    // A reasoning model on Chat Completions rejects tools at any effort
    // other than `none`; on the Responses API the two coexist.
    $body = configurationUpdateBody('gpt-6-astra', 'high');

    expect($body['reasoning'] ?? null)->toBeNull();
});
