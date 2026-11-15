<?php

declare(strict_types=1);

namespace Spora\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when a request names a value the agent's own bounds refuse.
 *
 * Its own type because the caller answers a 422 on it: a cap outside the
 * range is a malformed body, not a failed task.
 */
final class AgentPatchValidationException extends RuntimeException
{
    public const CODE_VALIDATION_ERROR = 'VALIDATION_ERROR';

    public function __construct(
        string $message,
        private readonly string $errorCode = self::CODE_VALIDATION_ERROR,
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
