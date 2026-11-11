<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\Responses\OpenAIResponsesDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A plugin tool's name carries a colon, and the Responses API's
 * function-name grammar forbids it. The name is sanitised at the driver
 * boundary — the tool definition builder is shared by every driver and
 * must not know about one provider's grammar — and the name the model
 * echoes is mapped back so the tool executor finds the tool.
 */
function toolNameDriver(array &$captured, string $model = 'gpt-6-astra'): OpenAIResponsesDriver
{
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured['body'] = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [[
                'type' => 'function_call',
                'id' => 'fc_1',
                'call_id' => 'call_1',
                'name' => $captured['body']['tools'][0]['name'],
                'arguments' => '{"q":"news"}',
            ]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    return new OpenAIResponsesDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.openai.com/v1',
        httpClient: $client,
    );
}

function pluginToolRequest(): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'search the news']],
        tools: [[
            'type' => 'function',
            'function' => [
                'name' => 'my-plugin:web_search',
                'description' => 'Searches the web.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['q' => ['type' => 'string']],
                    'required' => ['q'],
                ],
            ],
        ]],
    );
}

test('a plugin tool name with a colon is sanitised into the API grammar', function (): void {
    $captured = [];
    $driver = toolNameDriver($captured);
    $driver->complete(pluginToolRequest());

    expect($captured['body']['tools'][0]['name'])->toBe('my-plugin-web_search')
        ->and($captured['body']['tools'][0]['name'])->toMatch('/^[a-zA-Z0-9_-]+$/');
});

test('a tool name that already fits the grammar is left alone', function (): void {
    $captured = [];
    $driver = toolNameDriver($captured);
    $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'search the news']],
        tools: [[
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => 'Searches the web.',
                'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
            ],
        ]],
    ));

    expect($captured['body']['tools'][0]['name'])->toBe('web_search');
});

test('the name the model echoes is mapped back to the name Spora knows', function (): void {
    $captured = [];
    $driver = toolNameDriver($captured);
    $response = $driver->complete(pluginToolRequest());

    expect($response->toolCalls)->toHaveCount(1);
    expect($response->toolCalls[0]->toolName)->toBe('my-plugin:web_search');
    expect($response->toolCalls[0]->providerCallId)->toBe('call_1');
    expect($response->toolCalls[0]->arguments)->toBe(['q' => 'news']);
});

test('two plugin tools that would sanitise to the same name stay distinct', function (): void {
    $captured = [];
    $driver = toolNameDriver($captured);

    $driver->complete(new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'search']],
        tools: [
            [
                'type' => 'function',
                'function' => ['name' => 'my-plugin:web_search', 'description' => 'a', 'parameters' => []],
            ],
            [
                'type' => 'function',
                'function' => ['name' => 'my-plugin-web:search', 'description' => 'b', 'parameters' => []],
            ],
        ],
    ));

    $names = array_column($captured['body']['tools'], 'name');

    expect($names)->toBe(['my-plugin-web_search', 'my-plugin-web-search']);
    expect(array_unique($names))->toHaveCount(2);
});
