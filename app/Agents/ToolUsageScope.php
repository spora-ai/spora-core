<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Metering\MeteringContext;

/**
 * What the meter should be told about one tool call.
 *
 * The context is built once and used twice — once to ask the meter whether
 * the call may proceed, and once to tell it what the call cost. Building it
 * twice would cost a query per call and could resolve the operation to two
 * different answers.
 */
final readonly class ToolUsageScope
{
    public function __construct(
        public MeteringContext $context,
        public string $toolName,
        public string $toolClass,
        public ?string $operation,
    ) {}
}
