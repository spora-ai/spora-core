<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Preserves Anthropic's encrypted redacted-thinking payload for replay.
 */
final class RedactedThinkingBlockParser implements ContentBlockParser
{
    public function parse(array $block): ParsedContentBlock
    {
        return new ParsedContentBlock(
            displayReasoning: '[Redacted Thinking]',
            contentBlock: ContentBlock::redactedThinking((string) ($block['data'] ?? '')),
        );
    }
}
