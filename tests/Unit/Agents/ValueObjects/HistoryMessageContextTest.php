<?php

declare(strict_types=1);

namespace Tests\Unit\Agents\ValueObjects;

use Psr\Log\NullLogger;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Covers the persistence contract for HistoryMessageContext:
 *  - new shape decodes byte-identical (contentBlocks + usage)
 *  - rows with contentBlocks and no displayReasoning decode cleanly
 *  - unknown block types log and drop
 */
test('fromArray decodes a new-shape payload with contentBlocks and usage', function (): void {
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
        ->and($context->usage->cachedTokens)->toBe(4);
});

test('fromArray decodes a row with no contentBlocks and no usage', function (): void {
    $data = [
        'tool_call_payload' => '{}',
        'input_tokens' => 1,
        'output_tokens' => 2,
    ];

    $context = HistoryMessageContext::fromArray($data, new NullLogger());

    expect($context->contentBlocks)->toBe([])
        ->and($context->usage)->not->toBeNull()
        ->and($context->usage->provider)->toBe('unknown');
});

test('fromArray prefers new content_blocks over legacy keys when both are present', function (): void {
    // The legacy `reasoning` key (and any displayReasoning field) is
    // intentionally ignored — reasoning is reachable only through the
    // structured `content_blocks[]` of `type === "thinking"`.
    $data = [
        'content_blocks' => [
            ['type' => 'text', 'text' => 'fresh'],
        ],
        'reasoning' => 'legacy reasoning text',
        'display_reasoning' => 'also legacy',
    ];

    $context = HistoryMessageContext::fromArray($data, new NullLogger());

    expect($context->contentBlocks)->toHaveCount(1)
        ->and($context->contentBlocks[0]->text)->toBe('fresh');
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
