<?php

declare(strict_types=1);

namespace Spora\Metering;

/**
 * The default meter: allows everything and records nothing.
 *
 * Bound to {@see UsageMeterInterface} by the core so a Spora install with
 * no metering plugin pays nothing for the seam — no queries, no writes, no
 * behaviour change.
 */
final class NullUsageMeter implements UsageMeterInterface
{
    public function evaluate(MeteringContext $context, MeteringSubject $subject): ?UsageDecision
    {
        return null;
    }

    public function recordLlmUsage(MeteringContext $context, LlmUsage $usage): void
    {
        // Deliberately empty: the null meter is the seam's default, and it
        // must not cost an install anything.
    }

    public function recordToolUsage(MeteringContext $context, ToolUsage $usage): void
    {
        // Deliberately empty, like recordLlmUsage() above.
    }
}
