<?php

declare(strict_types=1);

namespace Spora\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when delight-im/auth's token bucket rejects an attempt because the
 * email or IP has exhausted its login budget.
 *
 * delight-im passes the estimated wait as the exception *code* (seconds), so
 * that value is carried here as a first-class property for the HTTP layer to
 * turn into a `Retry-After` header.
 */
final class TooManyRequestsException extends RuntimeException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many login attempts. Please try again later.');
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
