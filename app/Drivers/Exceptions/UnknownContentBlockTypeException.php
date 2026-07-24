<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a `ContentBlock` is constructed (or hydrated via
 * {@see \Spora\Drivers\ValueObjects\ContentBlock::fromArray()}) with a
 * `type` field that isn't one of the five recognised block kinds
 * (text, image, thinking, redacted_thinking, tool_use).
 *
 * Caught by `HistoryMessageContext::decodeContentBlocks()` when hydrating
 * task-history rows so a single unknown block cannot poison the entire
 * history replay — the row is dropped and a warning is logged instead.
 */
final class UnknownContentBlockTypeException extends InvalidArgumentException
{
    public function __construct(string $type)
    {
        parent::__construct("Unknown content block type: {$type}");
    }
}
