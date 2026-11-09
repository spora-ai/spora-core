<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when a request names a reasoning effort the framework does not
 * accept.
 *
 * Its own type because the caller answers a 422 on it: an unknown level is
 * a malformed body, not a failed task.
 *
 * The code says which refusal it is, because the two need different fixes
 * from the client: a malformed field has to be corrected, while a level the
 * agent's driver does not offer means the client's cached capability for
 * that agent is out of date.
 */
final class EffortValidationException extends RuntimeException
{
    /** The body field is not a usable effort at all. */
    public const CODE_MALFORMED = 'INVALID_EFFORT';

    /** The level is known, but the agent's driver does not offer it. */
    public const CODE_UNSUPPORTED = 'EFFORT_LEVEL_UNSUPPORTED';

    public function __construct(
        string $message,
        private readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    /**
     * The machine-readable code of the refusal.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
