<?php

declare(strict_types=1);

namespace Spora\Agents;

use RuntimeException;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Spora\Models\Task;
use Spora\Services\ContextWindowErrorParser;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Throwable;

/**
 * Error classification + friendly-message helpers extracted from
 * {@see Orchestrator} so the orchestrator stays under the SonarQube
 * `php:S1448` method-count cap.
 *
 * Package-private collaborator: constructed and called only by
 * {@see Orchestrator}. Pure logic — no logger or notification dependencies.
 */
final class ErrorClassifier
{
    /** Error codes that qualify for auto-retry. */
    public const RETRYABLE_ERROR_CODES = [
        'RATE_LIMIT',
        'SERVER_OVERLOADED',
        'SERVER_ERROR',
        'GATEWAY_ERROR',
        'AUTH_ERROR',
        'LLM_TIMEOUT',
        'ORPHANED',
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

        if ($e instanceof TimeoutExceptionInterface) {
            return 'LLM_TIMEOUT';
        }

        return 'UNKNOWN';
    }

    private function classifySpecificError(Throwable $e): ?string
    {
        return match (true) {
            $e instanceof LLMRateLimitException  => 'RATE_LIMIT',
            $e instanceof LLMRetryableException  => $this->classifyRetryableError($e),
            $e instanceof LLMProviderException    => $this->classifyProviderError($e),
            default                              => null,
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
