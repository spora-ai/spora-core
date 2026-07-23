<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\AnthropicCompatibleDriver;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Asserts Anthropic cache_control breakpoints land on the last system
 * block and the last tool block when enablePromptCaching is true.
 */
test('Anthropic driver attaches cache_control to last system and tool block by default', function (): void {
    $captured = null;
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'msg_breakpoints',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: $client,
    );

    $driver->complete(new \Spora\Drivers\ValueObjects\LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hello']],
        tools: [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'a_tool',
                    'description' => 'Does A',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ],
        ],
    ));

    expect($captured['system'])->toBeArray();
    expect($captured['system'][0]['type'])->toBe('text');
    expect($captured['system'][0]['text'])->toBe('You are helpful.');
    expect($captured['system'][0]['cache_control'])->toBe(['type' => 'ephemeral']);

    expect($captured['tools'])->toHaveCount(1);
    expect($captured['tools'][0]['name'])->toBe('a_tool');
    expect($captured['tools'][0]['cache_control'])->toBe(['type' => 'ephemeral']);

    expect($captured['messages'][0]['content'])->toBe('hello');
    expect($captured['messages'][0])->not->toHaveKey('cache_control');
});

test('Anthropic driver omits cache_control when enablePromptCaching is false', function (): void {
    $captured = null;
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = json_decode($options['body'], true);

        return new MockResponse(json_encode([
            'id' => 'msg_nobreakpoints',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]), ['http_code' => 200]);
    });

    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: $client,
        options: new \Spora\Drivers\AnthropicDriverOptions(enablePromptCaching: false),
    );

    $driver->complete(new \Spora\Drivers\ValueObjects\LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [['role' => 'user', 'content' => 'hello']],
        tools: [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'a_tool',
                    'description' => 'Does A',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ],
        ],
    ));

    expect($captured['system'])->toBe('You are helpful.');
    expect($captured['tools'][0])->not->toHaveKey('cache_control');
});
