<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Per-block-type parser contract.
 *
 * Each implementation handles a single LLM content block type (text, thinking,
 * redacted_thinking, ...) and returns a normalised {@see ParsedContentBlock}
 * describing what the block contributes to the response's content/reasoning.
 */
interface ContentBlockParser
{
    /**
     * @param array<string, mixed> $block
     */
    public function parse(array $block): ParsedContentBlock;
}
