<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Plan §12 B2b — Anthropic driver content-block wire shape.
 */
function makeAnthropicRequestDriver(string $model): AnthropicCompatibleDriver
{
    return new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: $model,
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );
}

test('text block renders with type:text', function (): void {
    $driver = makeAnthropicRequestDriver('claude-3-5-sonnet-20241022');
    $request = new LLMRequest(
        systemPrompt: 'You are helpful.',
        messages: [
            ['role' => 'user', 'content' => 'describe'],
        ],
        tools: [],
        maxTokens: 1024,
        temperature: 0.7,
    );
    $ref = new \ReflectionMethod($driver, 'convertMessages');
    $ref->setAccessible(true);
    $messages = $ref->invoke($driver, $request->messages);
    expect($messages[0]['content'])->toBe('describe');
});

test('image block renders with type:image and source:{type:base64,...}', function (): void {
    $driver = makeAnthropicRequestDriver('claude-3-5-sonnet-20241022');
    // The convertMessages() path expects ContentBlock value-objects
    // (since the LLMRequest typing has tightened) — build the message
    // as a plain array via reflection to exercise the renderer directly.
    $ref = new \ReflectionMethod($driver, 'convertMessages');
    $ref->setAccessible(true);
    $messages = $ref->invoke($driver, [[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'describe'],
            ['type' => 'image', 'mediaType' => 'image/png', 'base64' => 'AAAA'],
        ],
    ]]);
    $block = $messages[0]['content'][1];
    expect($block['type'])->toBe('image');
    expect($block['source']['type'])->toBe('base64');
    expect($block['source']['media_type'])->toBe('image/png');
    expect($block['source']['data'])->toBe('AAAA');
});

test('null content is converted to empty string for Anthropic', function (): void {
    $driver = makeAnthropicRequestDriver('claude-3-5-sonnet-20241022');
    $ref = new \ReflectionMethod($driver, 'convertMessages');
    $ref->setAccessible(true);
    $messages = $ref->invoke($driver, [
        ['role' => 'assistant', 'content' => null],
    ]);
    expect($messages[0]['content'])->toBe('');
});