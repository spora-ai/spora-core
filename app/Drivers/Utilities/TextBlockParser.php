<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Extracts unsigned inline reasoning for display without making it replayable.
 */
final class TextBlockParser implements ContentBlockParser
{
    public function parse(array $block): ParsedContentBlock
    {
        $cleaned = ThinkingTagExtractor::extract((string) ($block['text'] ?? ''));

        $text = $cleaned['textContent'];

        return new ParsedContentBlock(
            textContent: $text,
            displayReasoning: $cleaned['displayReasoning'],
            contentBlock: $text !== '' ? ContentBlock::text($text) : null,
        );
    }
}
