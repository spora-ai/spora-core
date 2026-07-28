<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Strips embedded reasoning tags from the text before surfacing it as a
 * `text` content block. Inline reasoning is not emitted here — the
 * OpenAI driver reads the raw content via {@see ThinkingTagExtractor::split()}
 * and surfaces the reasoning as a separate `thinking` block; Anthropic
 * uses provider-signed `thinking` blocks instead.
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
