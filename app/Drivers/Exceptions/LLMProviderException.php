<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use RuntimeException;

/**
 * Non-recoverable LLM API error.
 */
final class LLMProviderException extends RuntimeException
{
    public function isRetryable(): bool
    {
        // Check for retryable HTTP status codes embedded in the message
        $msg = $this->getMessage();

        // 5xx server errors are retryable
        if (preg_match('/\b(502|503|504|520|529)\b/', $msg)) {
            return true;
        }

        // 429 rate limit is handled separately by LLMRateLimitException
        // but if it lands here somehow, mark as retryable
        if (str_contains($msg, '429')) {
            return true;
        }

        return false;
    }
}
