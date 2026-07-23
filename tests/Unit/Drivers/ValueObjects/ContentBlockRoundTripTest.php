<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers\ValueObjects;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Round-trip coverage for ContentBlock's signed reasoning and tool-use types.
 */
test('thinking() round-trips text, signature, and metadata', function (): void {
    $original = ContentBlock::thinking('plan A', 'sig-abc', ['cache_ttl' => '5m', 'preset' => 'extended']);
    $round = ContentBlock::fromArray($original->toArray());

    expect($round->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($round->text)->toBe('plan A')
        ->and($round->signature)->toBe('sig-abc')
        ->and($round->metadata)->toBe(['cache_ttl' => '5m', 'preset' => 'extended']);
});

test('redactedThinking() keeps the encrypted data payload distinct from signature', function (): void {
    $original = ContentBlock::redactedThinking('encrypted-blob-payload-xyz');
    $round = ContentBlock::fromArray($original->toArray());

    expect($round->type)->toBe(ContentBlock::TYPE_REDACTED_THINKING)
        ->and($round->data)->toBe('encrypted-blob-payload-xyz')
        ->and($round->signature)->toBeNull();
});

test('toolUse() preserves id, name, and input', function (): void {
    $original = ContentBlock::toolUse('toolu_1', 'lookup', ['q' => 'paris']);
    $round = ContentBlock::fromArray($original->toArray());

    expect($round->type)->toBe(ContentBlock::TYPE_TOOL_USE)
        ->and($round->toolUseId)->toBe('toolu_1')
        ->and($round->toolName)->toBe('lookup')
        ->and($round->toolInput)->toBe(['q' => 'paris']);
});

test('fromArray accepts snake_case aliases for toolUseId and toolName', function (): void {
    $round = ContentBlock::fromArray([
        'type' => 'tool_use',
        'tool_use_id' => 'toolu_snake',
        'tool_name' => 'snake_lookup',
        'tool_input' => ['q' => 'rome'],
    ]);

    expect($round->toolUseId)->toBe('toolu_snake')
        ->and($round->toolName)->toBe('snake_lookup')
        ->and($round->toolInput)->toBe(['q' => 'rome']);
});
