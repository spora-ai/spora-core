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

test('unsigned thinking blocks are dropped from the Anthropic outbound assistant message', function (): void {
    // Cross-driver guard: agents that started on the OpenAI driver
    // store unsigned thinking blocks from `reasoning_content`; the
    // Anthropic path must drop them instead of forwarding
    // `{signature: ''}` (Anthropic 400s that).
    $builder = makeAnthropicRequestBuilder('claude-3-5-sonnet-20241022');
    $messages = $builder->convertMessages([
        [
            'role' => 'assistant',
            'content' => [
                ['type' => 'thinking', 'text' => 'unsigned reasoning', 'signature' => ''],
                ['type' => 'text', 'text' => 'visible answer'],
            ],
        ],
    ]);

    $content = $messages[0]['content'];
    expect($content)->toBeArray();

    $thinkingBlocks = array_values(array_filter(
        $content,
        static fn(array $b): bool => ($b['type'] ?? '') === 'thinking',
    ));
    expect($thinkingBlocks)->toBe([]);

    $textBlocks = array_values(array_filter(
        $content,
        static fn(array $b): bool => ($b['type'] ?? '') === 'text',
    ));
    expect($textBlocks)->toBe([['type' => 'text', 'text' => 'visible answer']]);
});

test('signed thinking blocks from a previous Anthropic turn still replay byte-identical', function (): void {
    // Companion to the unsigned-skip test above; pins the PR #163
    // chain-continuity contract on the Anthropic outbound path.
    $builder = makeAnthropicRequestBuilder('claude-3-5-sonnet-20241022');
    $messages = $builder->convertMessages([
        [
            'role' => 'assistant',
            'content' => [
                ['type' => 'thinking', 'text' => 'plan', 'signature' => 'sig-keep-me'],
                ['type' => 'text', 'text' => 'answer'],
            ],
        ],
    ]);

    $content = $messages[0]['content'];
    expect($content[0])->toBe([
        'type' => 'thinking',
        'thinking' => 'plan',
        'signature' => 'sig-keep-me',
    ]);
    expect($content[1])->toBe(['type' => 'text', 'text' => 'answer']);
});
