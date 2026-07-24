<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Spora\Drivers\Anthropic\AnthropicRequestBuilder;
use Spora\Drivers\AnthropicCompatibleDriver;
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

function makeAnthropicRequestBuilder(string $model): AnthropicRequestBuilder
{
    return new AnthropicRequestBuilder(
        apiKey: 'test',
        model: $model,
        enablePromptCaching: false,
        temperature: 0.7,
        thinkingBudget: null,
    );
}

test('text block renders with type:text', function (): void {
    $builder = makeAnthropicRequestBuilder('claude-3-5-sonnet-20241022');
    $messages = $builder->convertMessages([
        ['role' => 'user', 'content' => 'describe'],
    ]);
    expect($messages[0]['content'])->toBe('describe');
});

test('image block renders with type:image and source:{type:base64,...}', function (): void {
    $builder = makeAnthropicRequestBuilder('claude-3-5-sonnet-20241022');
    $messages = $builder->convertMessages([[
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
    $builder = makeAnthropicRequestBuilder('claude-3-5-sonnet-20241022');
    $messages = $builder->convertMessages([
        ['role' => 'assistant', 'content' => null],
    ]);
    expect($messages[0]['content'])->toBe('');
});
