<?php

declare(strict_types=1);

namespace Spora\Agents\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a string field on `tool_calls` would be truncated by the live
 * schema's column length. Surfaces the field name, the actual length, the
 * column's max length, and the originating tool class so the dev reading the
 * stack trace can fix the source rather than chase a SQLSTATE 22001.
 *
 * Triggered by {@see \Spora\Agents\ToolCallInsertGuard::assertInsertable()}
 * from {@see \Spora\Models\ToolCall::save()} before delegating to Eloquent.
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
