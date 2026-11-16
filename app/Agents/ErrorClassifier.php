<?php

declare(strict_types=1);

namespace Spora\Agents;

use RuntimeException;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Metering\Exceptions\UsageLimitExceededException;
use Spora\Models\Task;
use Spora\Services\ContextWindowErrorParser;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Maps orchestrator-level exceptions and HTTP/timeout failures to retryable
 * error codes and user-facing friendly messages.
 *
 * Package-private collaborator: constructed and called only by
 * {@see Orchestrator}. Pure logic — no logger or notification dependencies.
 */
final class ErrorClassifier
{
    /**
     * Error codes that qualify for auto-retry.
     *
     * `USAGE_LIMIT_REACHED` is deliberately absent: a hard usage decision is
     * terminal, and a retry would spend the very budget the decision refused.
     *
     * `ORPHANED` is the code the reaper used to write; `WORKER_DISCONNECTED`
     * is what it writes now. Both are here: the reaper arms the retry it
     * just created, so the code it writes has to be one this list accepts.
     */
    public const RETRYABLE_ERROR_CODES = [
        'RATE_LIMIT',
        'SERVER_OVERLOADED',
        'SERVER_ERROR',
        'GATEWAY_ERROR',
        'AUTH_ERROR',
        'LLM_TIMEOUT',
        'ORPHANED',
        'WORKER_DISCONNECTED',
    ];

    public function __construct()
    {
        // Pure logic — no logger, notification, or other collaborator dependencies.
        // Kept as an explicit (empty) constructor so the class can grow collaborators
        // later without breaking the Orchestrator's `new ErrorClassifier()` call site.
    }

    /**
     * Mark a task as FAILED with NO_LLM_CONFIGURATION if the underlying
     * exception is an {@see LlmConfigurationMissingException}.
     */
    public function markTaskNoLlmConfiguration(int $taskId, RuntimeException $e): void
    {
        if (!str_contains($e->getMessage(), 'No LLM configuration')) {
            return;
        }

        Task::where('id', $taskId)->update([
            'status'         => 'FAILED',
            'failure_reason' => $e->getMessage(),
            'error_code'     => 'NO_LLM_CONFIGURATION',
            'error_message'  => 'No LLM configuration set. Please configure an LLM driver or set a global default.',
        ]);
    }

    public function classifyError(Throwable $e): string
    {
        $classified = $this->classifySpecificError($e);
        if ($classified !== null) {
            return $classified;
        }

        // A timeout is a transport failure too, but it has its own code —
        // so the timeout branch has to come first, or every timeout would
        // be reported as a generic gateway failure.
        //
        // A provider that cannot be reached at all — DNS failure, connection
        // reset, refused TLS handshake — is as transient as a 5xx, and the
        // most common failure there is. Without the transport branch it
        // lands on UNKNOWN, which the auto-retry policy does not accept, so
        // an agent's configured retry never arms for the one failure a
        // retry policy exists for.
        return match (true) {
            $e instanceof TimeoutExceptionInterface  => 'LLM_TIMEOUT',
            $e instanceof TransportExceptionInterface => 'GATEWAY_ERROR',
            default                                  => 'UNKNOWN',
        };
    }

    private function classifySpecificError(Throwable $e): ?string
    {
        return match (true) {
            $e instanceof UsageLimitExceededException => 'USAGE_LIMIT_REACHED',
            $e instanceof LLMRateLimitException       => 'RATE_LIMIT',
            $e instanceof LLMRetryableException       => $this->classifyRetryableError($e),
            $e instanceof LLMProviderException        => $this->classifyProviderError($e),
            default                                  => null,
        };
    }

    private function classifyRetryableError(LLMRetryableException $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '529')) {
            return 'SERVER_OVERLOADED';
        }
        if (str_contains($msg, '520') || str_contains($msg, '500')) {
            return 'SERVER_ERROR';
        }
        return 'GATEWAY_ERROR';
    }

    private function classifyProviderError(LLMProviderException $e): ?string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '401') || str_contains($msg, '403')) {
            return 'AUTH_ERROR';
        }
        if (str_contains($msg, '400')) {
            return 'BAD_REQUEST';
        }

        return $e->isRetryable() ? 'GATEWAY_ERROR' : null;
    }

    /**
     * @return array<string, string>
     */
    public function friendlyMessages(): array
    {
        return [
            'RATE_LIMIT'        => 'The AI service is busy. Try again in a moment.',
            'SERVER_OVERLOADED' => 'The AI service is under high load. Try again shortly.',
            'SERVER_ERROR'      => 'The AI service encountered an error. Please try again.',
            'GATEWAY_ERROR'     => 'The AI service is temporarily unavailable. Try again shortly.',
            'AUTH_ERROR'        => 'API authentication failed. Please check your API key.',
            'LLM_TIMEOUT'       => 'The AI request timed out. Check your model or increase the timeout setting.',
            'BAD_REQUEST'           => 'Invalid request. Please check your agent configuration.',
            'NO_LLM_CONFIGURATION'  => 'No LLM configuration set. Please configure an LLM driver or set a global default.',
            'TOOL_ERROR'           => 'A tool encountered an error. Check the task history for details.',
            'USAGE_LIMIT_REACHED'  => 'The usage limit was reached, so the run stopped. Check your usage quota or raise the limit.',
            'UNKNOWN'           => 'An unexpected error occurred. Please try again.',
        ];
    }

    /**
     * Build friendly message for an error, with extra context for context window errors.
     */
    public function friendlyMessageForError(Throwable $e, string $errorCode): string
    {
        $base = $this->friendlyMessages()[$errorCode] ?? $this->friendlyMessages()['UNKNOWN'];

        if ($this->isContextWindowError($e)) {
            $actualLimit = $this->extractActualContextWindow($e);
            if ($actualLimit !== null) {
                return "Context window exceeded ({$actualLimit} tokens). Try reducing history depth, choosing a model with larger context, or adjusting max_tokens_output.";
            }
        }

        return $base;
    }

    public function isContextWindowError(Throwable $e): bool
    {
        if (!$e instanceof LLMProviderException) {
            return false;
        }

        $rawBody = $e->getMessage();
        // Extract JSON body from "Provider API error N: {...}" format
        if (preg_match('/\{.*\}/s', $rawBody, $matches)) {
            $parser = new ContextWindowErrorParser();
            return $parser->isContextWindowError($matches[0]);
        }

        return false;
    }

    public function extractActualContextWindow(Throwable $e): ?int
    {
        if (!$e instanceof LLMProviderException) {
            return null;
        }

        if (preg_match('/\{.*\}/s', $e->getMessage(), $matches)) {
            $parser = new ContextWindowErrorParser();
            $parsed = $parser->parse($matches[0]);
            return $parsed['actual_context_window'];
        }

        return $e->getActualContextWindow();
    }
}
