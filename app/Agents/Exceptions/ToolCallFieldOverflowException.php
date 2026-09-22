<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a string field on `tool_calls` would be silently truncated
 * by the column's declared max length. The structured fields let the dev
 * reading the stack trace fix the source rather than chase a SQLSTATE 22001.
 */
final class ToolCallFieldOverflowException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly int $actualLength,
        public readonly int $maxLength,
        public readonly string $toolClass,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                "tool_calls.%s for %s is %d chars; column limit is %d.",
                $field,
                $toolClass,
                $actualLength,
                $maxLength,
            ),
            0,
            $previous,
        );
    }
}
