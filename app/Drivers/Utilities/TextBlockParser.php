<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Strips embedded reasoning tags from the text before surfacing it as a
 * `text` content block. The extracted reasoning itself is **not** emitted
 * here — the OpenAI compatible driver reads the original content string
 * via {@see ThinkingTagExtractor::split()} and emits any inline reasoning
 * as a separate `thinking` block; for Anthropic, provider-signed
 * `thinking` blocks are the canonical source. Tags that survive into
 * here (e.g. when the OpenAI driver passes an already-split payload) are
 * silently dropped on the Anthropic surface.
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
