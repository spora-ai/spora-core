<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\Config;
use Spora\Http\Exceptions\AgentPatchValidationException;

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
     * Refuse a cap this installation does not allow.
     *
     * The refusal is thrown rather than returned, because the message
     * reaches the operator through the error envelope — and a message that
     * only ever existed as a return value would be untranslatable.
     *
     * @param mixed $value
     * @throws AgentPatchValidationException when the value is not a cap this installation allows
     */
    public function assertValid(mixed $value): void
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new AgentPatchValidationException(
                sprintf('max_steps must be an integer between 1 and %d.', $this->limit()),
            );
        }

        $cap = (int) $value;

        if ($cap < 1 || $cap > $this->limit()) {
            throw new AgentPatchValidationException(
                sprintf('max_steps must be between 1 and %d.', $this->limit()),
            );
        }
    }
}
