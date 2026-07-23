<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\ContentBlock;

/**
 * Normalized contribution of one provider content block.
 */
final readonly class ParsedContentBlock
{
    public function __construct(
        public string $textContent = '',
        public ?string $displayReasoning = null,
        public ?ContentBlock $contentBlock = null,
    ) {}
}
