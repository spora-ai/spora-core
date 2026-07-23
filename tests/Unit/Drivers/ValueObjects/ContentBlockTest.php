<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers\ValueObjects;

use Error;
use InvalidArgumentException;
use Spora\Drivers\Exceptions\UnknownContentBlockTypeException;
use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Cover {@see ContentBlock} — the multi-modal message intermediate
 * representation that {@see \Spora\Drivers\OpenAICompatibleDriver} and
 * {@see \Spora\Drivers\AnthropicCompatibleDriver} serialize to their
 * provider-specific wire shapes.
 */
test('text() constructs a text block with the given text', function (): void {
    $block = ContentBlock::text('hello world');

    expect($block->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($block->text)->toBe('hello world')
        ->and($block->mediaType)->toBeNull()
        ->and($block->base64)->toBeNull()
        ->and($block->url)->toBeNull();
});

test('imageBase64() constructs an image block with mediaType and base64', function (): void {
    $block = ContentBlock::imageBase64('image/png', 'BASE64DATA');

    expect($block->type)->toBe(ContentBlock::TYPE_IMAGE)
        ->and($block->text)->toBeNull()
        ->and($block->mediaType)->toBe('image/png')
        ->and($block->base64)->toBe('BASE64DATA')
        ->and($block->url)->toBeNull();
});

test('imageUrl() constructs an image block with the URL', function (): void {
    $block = ContentBlock::imageUrl('https://example.invalid/cat.png');

    expect($block->type)->toBe(ContentBlock::TYPE_IMAGE)
        ->and($block->text)->toBeNull()
        ->and($block->mediaType)->toBeNull()
        ->and($block->base64)->toBeNull()
        ->and($block->url)->toBe('https://example.invalid/cat.png');
});

test('exposes the TYPE_TEXT and TYPE_IMAGE constants', function (): void {
    expect(ContentBlock::TYPE_TEXT)->toBe('text')
        ->and(ContentBlock::TYPE_IMAGE)->toBe('image');
});

test('rejects unknown type values via the constructor', function (): void {
    expect(static fn(): ContentBlock => new ContentBlock('audio'))
        ->toThrow(InvalidArgumentException::class, 'Unknown content block type: audio');
});

test('accepts an empty string for imageUrl() — caller decides validity', function (): void {
    $block = ContentBlock::imageUrl('');

    expect($block->url)->toBe('')
        ->and($block->type)->toBe(ContentBlock::TYPE_IMAGE);
});

test('properties are readonly — they cannot be reassigned', function (): void {
    $block = ContentBlock::text('hi');

    try {
        /** @phpstan-ignore-next-line intentional invalid write to assert readonly */
        $block->type = 'image';
        $this->fail('Expected an Error when modifying a readonly property');
    } catch (Error $e) {
        expect($e->getMessage())->toContain('readonly');
    }
});

test('thinking() builds a signed block with optional metadata', function (): void {
    $block = ContentBlock::thinking('plan', 'sig-1', ['cache_ttl' => '5m']);

    expect($block->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($block->text)->toBe('plan')
        ->and($block->signature)->toBe('sig-1')
        ->and($block->metadata)->toBe(['cache_ttl' => '5m']);
});

test('redactedThinking() carries the encrypted data payload', function (): void {
    $block = ContentBlock::redactedThinking('encrypted-blob');

    expect($block->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING)
        ->and($block->data)->toBe('encrypted-blob')
        ->and($block->signature)->toBeNull();
});

test('toolUse() builds a tool_use block', function (): void {
    $block = ContentBlock::toolUse('toolu_1', 'lookup', ['q' => 'paris']);

    expect($block->type)->toBe(ContentBlock::TYPE_TOOL_USE)
        ->and($block->toolUseId)->toBe('toolu_1')
        ->and($block->toolName)->toBe('lookup')
        ->and($block->toolInput)->toBe(['q' => 'paris']);
});

test('toArray round-trips through fromArray for every block type', function (): void {
    $blocks = [
        ContentBlock::text('hi'),
        ContentBlock::thinking('plan', 'sig-1', ['cache_ttl' => '5m']),
        ContentBlock::redactedThinking('encrypted-blob'),
        ContentBlock::toolUse('toolu_1', 'lookup', ['q' => 'paris']),
        ContentBlock::imageBase64('image/png', 'AAAA'),
        ContentBlock::imageUrl('https://example.invalid/cat.png'),
    ];

    foreach ($blocks as $original) {
        $round = ContentBlock::fromArray($original->toArray());
        expect($round->type)->toBe($original->type);
        expect($round->text)->toBe($original->text);
        expect($round->signature)->toBe($original->signature);
        expect($round->data)->toBe($original->data);
        expect($round->mediaType)->toBe($original->mediaType);
        expect($round->base64)->toBe($original->base64);
        expect($round->url)->toBe($original->url);
        expect($round->toolUseId)->toBe($original->toolUseId);
        expect($round->toolName)->toBe($original->toolName);
        expect($round->toolInput)->toBe($original->toolInput);
        expect($round->metadata)->toBe($original->metadata);
    }
});

test('fromArray throws UnknownContentBlockTypeException for unknown types', function (): void {
    ContentBlock::fromArray(['type' => 'audio']);
})->throws(UnknownContentBlockTypeException::class);
