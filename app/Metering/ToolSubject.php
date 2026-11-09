<?php

declare(strict_types=1);

namespace Spora\Metering;

/**
 * A tool execution about to run.
 *
 * `toolClass` is the fully-qualified class name and is the stable key: the
 * wire name comes from the `#[Tool]` attribute and changes between plugin
 * releases, while the class does not. `operation` narrows a multi-operation
 * tool down to the operation the call actually dispatches to.
 */
final readonly class ToolSubject implements MeteringSubject
{
    public function __construct(
        public string $toolName,
        public string $toolClass,
        public ?string $operation,
    ) {}

    public function kind(): string
    {
        return self::KIND_TOOL;
    }
}
