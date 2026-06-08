<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Parses a `thinking` content block: the block's `thinking` key is treated
 * as chain-of-thought reasoning. Empty/missing keys yield an empty string
 * to preserve concatenation semantics in the dispatcher.
 */
final class ThinkingBlockParser implements ContentBlockParser
{
    public function parse(array $block): ParsedContentBlock
    {
        return new ParsedContentBlock(
            content: '',
            reasoning: (string) ($block['thinking'] ?? ''),
        );
    }
}
