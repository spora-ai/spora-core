<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Preserves Anthropic's signed thinking block for byte-identical replay.
 */
final class ThinkingBlockParser implements ContentBlockParser
{
    public function parse(array $block): ParsedContentBlock
    {
        $thinking = (string) ($block['thinking'] ?? '');

        return new ParsedContentBlock(
            displayReasoning: $thinking,
            contentBlock: ContentBlock::thinking(
                $thinking,
                (string) ($block['signature'] ?? ''),
                is_array($block['metadata'] ?? null) ? $block['metadata'] : null,
            ),
        );
    }
}
