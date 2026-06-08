<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Parses a `redacted_thinking` block. Anthropic emits this when signature
 * verification fails or the reasoning is otherwise blocked; we surface a
 * stable `[Redacted Thinking]` marker so downstream consumers know that
 * reasoning existed but was withheld.
 */
final class RedactedThinkingBlockParser implements ContentBlockParser
{
    public function parse(array $block): ParsedContentBlock
    {
        return new ParsedContentBlock(
            content: '',
            reasoning: '[Redacted Thinking]',
        );
    }
}
