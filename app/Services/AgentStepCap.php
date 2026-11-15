<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\Config;

/**
 * The step cap an agent may carry.
 *
 * The cap is the only thing bounding the tick's recursion. A tick that
 * finishes a tool batch hands the task straight back to the loop
 * (completeTickAfterTools → continueOrHandOff → tick → runTick), so an
 * agent whose model keeps calling tools — a confused model, or a tool
 * that answers in a way the model reads as "not yet" — recurses until the
 * worker exhausts its memory and dies. That is not a slow burn: it is a
 * fatal error in the middle of a tick.
 *
 * The ceiling is what makes that impossible. It is the largest cap an
 * agent may carry, and it is configurable because a long-running agentic
 * workflow needs more room than a chat does.
 */
final class AgentStepCap
{
    /** The largest cap an agent may carry when nothing says otherwise. */
    public const DEFAULT_LIMIT = 200;

    /** The cap a new agent carries when the caller names none. */
    public const DEFAULT_CAP = 100;

    public function __construct(private readonly ?Config $config = null) {}

    /**
     * The largest cap an agent may carry.
     */
    public function limit(): int
    {
        $limit = $this->config?->getInt('SPORA_MAX_STEPS');

        return ($limit !== null && $limit > 0) ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * The cap a new agent carries when the caller names none.
     *
     * Never above the ceiling: a limit the operator set below the default
     * would otherwise leave every new agent with a cap it may not have.
     */
    public function defaultCap(): int
    {
        return min(self::DEFAULT_CAP, $this->limit());
    }

    /**
     * Whether a value is a cap this installation allows.
     */
    public function isValid(int $value): bool
    {
        return $value >= 1 && $value <= $this->limit();
    }
}
