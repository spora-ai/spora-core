<?php

declare(strict_types=1);

namespace Spora\Drivers\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Non-recoverable LLM API error.
 */
final class LLMProviderException extends RuntimeException
{
    private int|null $actualContextWindow = null;
    private string|null $errorType = null;

    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        int|null $actualContextWindow = null,
        string|null $errorType = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->actualContextWindow = $actualContextWindow;
        $this->errorType = $errorType;
    }

    public function isRetryable(): bool
    {
        $msg = $this->getMessage();

        // 5xx server errors are retryable
        if (preg_match('/\b(502|503|504|520|529)\b/', $msg)) {
            return true;
        }

        // 429 rate limit is handled separately by LLMRateLimitException
        if (str_contains($msg, '429')) {
            return true;
        }

        return false;
    }

    public function getActualContextWindow(): int|null
    {
        return $this->actualContextWindow;
    }

    public function getErrorType(): string|null
    {
        return $this->errorType;
    }

    public function withParsedError(string $rawBody): self
    {
        $parser = new \Spora\Services\ContextWindowErrorParser();
        $parsed = $parser->parse($rawBody);

        $clone = new self(
            $this->getMessage(),
            $this->getCode(),
            $this->getPrevious(),
            $parsed['actual_context_window'] ?? $this->actualContextWindow,
            $parsed['error_type'] ?? $this->errorType,
        );

        return $clone;
    }
}
