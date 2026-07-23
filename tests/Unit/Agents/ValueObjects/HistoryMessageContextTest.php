<?php

declare(strict_types=1);

namespace Tests\Unit\Agents\ValueObjects;

use Psr\Log\NullLogger;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\Usage;

/**
 * Covers the migration contract for HistoryMessageContext:
 *  - new shape persists and decodes byte-identical
 *  - legacy {reasoning} rows decode into display_reasoning only
 *  - mixed-version rows prefer new content_blocks
 *  - unknown block types log and drop
 */
test('fromArray decodes a new-shape payload including contentBlocks, usage, and displayReasoning', function (): void {
    $data = [
        'tool_call_id' => 'call_1',
        'tool_name' => 'lookup',
        'tool_call_payload' => '{}',
        'input_tokens' => 12,
        'output_tokens' => 34,
        'content_blocks' => [
            ['type' => 'text', 'text' => 'hello'],
            ['type' => 'thinking', 'text' => 'plan', 'signature' => 'sig-1'],
        ],
        'usage' => [
            'input_tokens' => 12,
            'output_tokens' => 34,
            'reasoning_tokens' => 0,
            'cached_tokens' => 4,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => 0,
            'provider' => 'openai',
        ],
        'display_reasoning' => 'plan',
    ];

    $context = HistoryMessageContext::fromArray($data, new NullLogger());

    expect($context->inputTokens)->toBe(12)
        ->and($context->outputTokens)->toBe(34)
        ->and($context->contentBlocks)->toHaveCount(2)
        ->and($context->contentBlocks[0]->type)->toBe(ContentBlock::TYPE_TEXT)
        ->and($context->contentBlocks[0]->text)->toBe('hello')
        ->and($context->contentBlocks[1]->type)->toBe(ContentBlock::TYPE_THINKING)
        ->and($context->contentBlocks[1]->signature)->toBe('sig-1')
        ->and($context->usage)->not->toBeNull()
        ->and($context->usage->provider)->toBe('openai')
        ->and($context->usage->cachedTokens)->toBe(4)
        ->and($context->displayReasoning)->toBe('plan');
});

test('fromArray decodes legacy {reasoning} rows into display_reasoning when content_blocks is empty', function (): void {
    $data = [
        'tool_call_payload' => '{}',
        'input_tokens' => 1,
        'output_tokens' => 2,
        'reasoning' => 'legacy reasoning text',
    ];

    $context = HistoryMessageContext::fromArray($data, new NullLogger());

    expect($context->contentBlocks)->toBe([])
        ->and($context->displayReasoning)->toBe('legacy reasoning text')
        ->and($context->usage)->not->toBeNull()
        ->and($context->usage->provider)->toBe('unknown');
});

test('fromArray prefers new content_blocks over legacy reasoning when both are present', function (): void {
    $data = [
        'content_blocks' => [
            ['type' => 'text', 'text' => 'fresh'],
        ],
        'reasoning' => 'legacy reasoning text',
    ];

    $context = HistoryMessageContext::fromArray($data, new NullLogger());

    expect($context->contentBlocks)->toHaveCount(1)
        ->and($context->contentBlocks[0]->text)->toBe('fresh')
        ->and($context->displayReasoning)->toBeNull();
});

test('fromArray drops unknown block types via a warning rather than crashing', function (): void {
    $data = [
        'content_blocks' => [
            ['type' => 'mystery'],
            ['type' => 'text', 'text' => 'kept'],
        ],
    ];

    $context = HistoryMessageContext::fromArray($data, new NullLogger());

    expect($context->contentBlocks)->toHaveCount(1)
        ->and($context->contentBlocks[0]->type)->toBe(ContentBlock::TYPE_TEXT);
});

test('toArray serialises contentBlocks, usage, and displayReasoning as JSON-friendly arrays', function (): void {
    $context = new HistoryMessageContext(
        contentBlocks: [
            ContentBlock::text('hi'),
            ContentBlock::thinking('plan', 'sig-1'),
        ],
        usage: new Usage(inputTokens: 1, outputTokens: 2, provider: 'anthropic'),
        displayReasoning: 'plan',
    );

    $serialized = $context->toArray();

    expect($serialized['content_blocks'][0])->toBe(['type' => 'text', 'text' => 'hi', 'signature' => null, 'data' => null, 'mediaType' => null, 'base64' => null, 'url' => null, 'toolUseId' => null, 'toolName' => null, 'toolInput' => null, 'metadata' => null])
        ->and($serialized['content_blocks'][1])->toBe(['type' => 'thinking', 'text' => 'plan', 'signature' => 'sig-1', 'data' => null, 'mediaType' => null, 'base64' => null, 'url' => null, 'toolUseId' => null, 'toolName' => null, 'toolInput' => null, 'metadata' => null])
        ->and($serialized['usage']['provider'])->toBe('anthropic')
        ->and($serialized['display_reasoning'])->toBe('plan');
});
