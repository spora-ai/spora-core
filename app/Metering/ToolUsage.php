<?php

declare(strict_types=1);

namespace Spora\Metering;

/**
 * The outcome of one tool execution.
 *
 * A tool that fails still cost something, so the record carries the outcome
 * rather than only the successes — a meter that charges per call charges
 * failures too.
 */
final readonly class ToolUsage
{
    public function __construct(
        public string $toolName,
        public string $toolClass,
        public ?string $operation,
        public bool $succeeded,
    ) {}
}
