<?php

declare(strict_types=1);

namespace Spora\Metering\Exceptions;

use RuntimeException;
use Spora\Metering\UsageDecision;

/**
 * A hard usage decision stopped the run.
 *
 * Thrown by the core at the two moments it can veto: before a provider
 * request leaves and before a tool executes. It carries the decision so the
 * caller can write the operator-facing reason onto the task row.
 */
final class UsageLimitExceededException extends RuntimeException
{
    public function __construct(private readonly UsageDecision $decision)
    {
        parent::__construct($decision->reason);
    }

    public function decision(): UsageDecision
    {
        return $this->decision;
    }
}
