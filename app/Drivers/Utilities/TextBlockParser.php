<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Parses a `text` content block. The raw text may itself contain embedded
 * `<think>` / `<thinking>` / `<thought>` reasoning tags which are extracted
 * via {@see ThinkingTagExtractor}.
 */
final class TextBlockParser implements ContentBlockParser
{
    public function parse(array $block): ParsedContentBlock
    {
        $rawText  = (string) ($block['text'] ?? '');
        $cleaned  = ThinkingTagExtractor::extract($rawText);

        return new ParsedContentBlock(
            content: $cleaned['content'],
            reasoning: $cleaned['reasoning'],
        );
    }
}
