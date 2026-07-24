<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Strips embedded `<think>…</think>` reasoning tags from the text before
 * surfacing it as a `text` content block. The extracted reasoning itself is
 * intentionally not surfaced — operators get reasoning only from providers
 * that return signed `thinking` content blocks (Anthropic extended
 * thinking). Unsigned inline reasoning is silently dropped on the floor.
 */
final class TextBlockParser implements ContentBlockParser
{
    public function parse(array $block): ParsedContentBlock
    {
        $text = ThinkingTagExtractor::strip((string) ($block['text'] ?? ''));

        return new ParsedContentBlock(
            textContent: $text,
            contentBlock: $text !== '' ? ContentBlock::text($text) : null,
        );
    }
}
