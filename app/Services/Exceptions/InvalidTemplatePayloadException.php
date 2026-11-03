<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * A template payload the validator refused.
 *
 * Carries the validator's own error list rather than a flattened message,
 * so the caller can answer with the same detail the import endpoint gives.
 */
final class InvalidTemplatePayloadException extends RuntimeException
{
    /**
     * @param list<array{code: string, severity: string, message: string, path?: string}> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0]['message'] ?? 'The template payload is not valid.');
    }
}
