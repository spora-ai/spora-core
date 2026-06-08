<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Normalised contribution of a single LLM content block.
 *
 * `content` is appended to the response text; `reasoning` (when non-null) is
 * appended to the chain-of-thought stream. Either or both may be empty.
 */
final class ParsedContentBlock
{
    public function __construct(
        public readonly string $content = '',
        public readonly ?string $reasoning = null,
    ) {}
}
