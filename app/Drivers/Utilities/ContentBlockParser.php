<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

/**
 * Per-block parser contract used to separate replayable provider state from display text.
 */
interface ContentBlockParser
{
    /**
     * @param array<string, mixed> $block
     */
    public function parse(array $block): ParsedContentBlock;
}
