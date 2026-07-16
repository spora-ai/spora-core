<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers\ValueObjects;

use InvalidArgumentException;
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

    $setType = static function () use ($block): void {
        /** @phpstan-ignore-next-line intentional invalid write to assert readonly */
        $block->type = 'image';
    };

    expect($setType)->toThrow(Error::class);
});