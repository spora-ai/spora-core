<?php

declare(strict_types=1);

namespace Spora\Tests\Unit;

use RuntimeException;
use Spora\Drivers\Exceptions\LLMProviderException;
use Spora\Drivers\Exceptions\LLMRateLimitException;
use Spora\Drivers\Exceptions\LLMRetryableException;
use Throwable;

/**
 * Standalone classification and message functions mirroring the logic
 * implemented in Orchestrator. Used for unit testing without instantiating
 * the full Orchestrator.
 */
function classifyError(Throwable $e): string
{
    if ($e instanceof LLMRateLimitException) {
        return 'RATE_LIMIT';
    }

    if ($e instanceof LLMRetryableException) {
        $msg = $e->getMessage();
        if (str_contains($msg, '529')) {
            return 'SERVER_OVERLOADED';
        }
        if (str_contains($msg, '520') || str_contains($msg, '500')) {
            return 'SERVER_ERROR';
        }
        return 'GATEWAY_ERROR';
    }

    if ($e instanceof LLMProviderException) {
        $msg = $e->getMessage();
        if (str_contains($msg, '401') || str_contains($msg, '403')) {
            return 'AUTH_ERROR';
        }
        if (str_contains($msg, '400')) {
            return 'BAD_REQUEST';
        }
        if ($e->isRetryable()) {
            return 'GATEWAY_ERROR';
        }
    }

    return 'UNKNOWN';
}

/**
 * @return array<string, string>
 */
function friendlyMessages(): array
{
    return [
        'RATE_LIMIT'        => 'The AI service is busy. Try again in a moment.',
        'SERVER_OVERLOADED' => 'The AI service is under high load. Try again shortly.',
        'SERVER_ERROR'      => 'The AI service encountered an error. Please try again.',
        'GATEWAY_ERROR'     => 'The AI service is temporarily unavailable. Try again shortly.',
        'AUTH_ERROR'        => 'API authentication failed. Please check your API key.',
        'BAD_REQUEST'       => 'Invalid request. Please check your agent configuration.',
        'TOOL_ERROR'        => 'A tool encountered an error. Check the task history for details.',
        'UNKNOWN'           => 'An unexpected error occurred. Please try again.',
    ];
}

function friendlyMessage(string $errorCode): string
{
    return friendlyMessages()[$errorCode] ?? friendlyMessages()['UNKNOWN'];
}

describe('OrchestratorErrorClassification', function () {
    describe('classifyError()', function () {
        it('maps LLMRateLimitException to RATE_LIMIT', function () {
            expect(classifyError(new LLMRateLimitException('Rate limit exceeded')))
                ->toBe('RATE_LIMIT');
        });

        it('maps LLMRetryableException with 529 to SERVER_OVERLOADED', function () {
            expect(classifyError(new LLMRetryableException('OpenAI API error 529: overload')))
                ->toBe('SERVER_OVERLOADED');
        });

        it('maps LLMRetryableException with 500 to SERVER_ERROR', function () {
            expect(classifyError(new LLMRetryableException('OpenAI API error 500: unknown')))
                ->toBe('SERVER_ERROR');
        });

        it('maps LLMRetryableException with 520 to SERVER_ERROR', function () {
            expect(classifyError(new LLMRetryableException('Anthropic error 520')))
                ->toBe('SERVER_ERROR');
        });

        it('maps LLMRetryableException with 502 to GATEWAY_ERROR', function () {
            expect(classifyError(new LLMRetryableException('Gateway timeout 502')))
                ->toBe('GATEWAY_ERROR');
        });

        it('maps LLMRetryableException with 503 to GATEWAY_ERROR', function () {
            expect(classifyError(new LLMRetryableException('Service unavailable 503')))
                ->toBe('GATEWAY_ERROR');
        });

        it('maps LLMRetryableException with 504 to GATEWAY_ERROR', function () {
            expect(classifyError(new LLMRetryableException('Gateway timeout 504')))
                ->toBe('GATEWAY_ERROR');
        });

        it('maps LLMRetryableException with 501 to GATEWAY_ERROR', function () {
            expect(classifyError(new LLMRetryableException('Server error 501')))
                ->toBe('GATEWAY_ERROR');
        });

        it('maps LLMProviderException with 401 to AUTH_ERROR', function () {
            expect(classifyError(new LLMProviderException('OpenAI API error 401: unauthorized')))
                ->toBe('AUTH_ERROR');
        });

        it('maps LLMProviderException with 403 to AUTH_ERROR', function () {
            expect(classifyError(new LLMProviderException('Anthropic API error 403: forbidden')))
                ->toBe('AUTH_ERROR');
        });

        it('maps LLMProviderException with 400 to BAD_REQUEST', function () {
            expect(classifyError(new LLMProviderException('Bad request 400')))
                ->toBe('BAD_REQUEST');
        });

        it('maps unknown exception to UNKNOWN', function () {
            expect(classifyError(new RuntimeException('Some unrelated error')))
                ->toBe('UNKNOWN');
        });
    });

    describe('friendlyMessage()', function () {
        it('returns correct message for RATE_LIMIT', function () {
            expect(friendlyMessage('RATE_LIMIT'))
                ->toBe('The AI service is busy. Try again in a moment.');
        });

        it('returns correct message for SERVER_OVERLOADED', function () {
            expect(friendlyMessage('SERVER_OVERLOADED'))
                ->toBe('The AI service is under high load. Try again shortly.');
        });

        it('returns correct message for SERVER_ERROR', function () {
            expect(friendlyMessage('SERVER_ERROR'))
                ->toBe('The AI service encountered an error. Please try again.');
        });

        it('returns correct message for GATEWAY_ERROR', function () {
            expect(friendlyMessage('GATEWAY_ERROR'))
                ->toBe('The AI service is temporarily unavailable. Try again shortly.');
        });

        it('returns correct message for AUTH_ERROR', function () {
            expect(friendlyMessage('AUTH_ERROR'))
                ->toBe('API authentication failed. Please check your API key.');
        });

        it('returns correct message for BAD_REQUEST', function () {
            expect(friendlyMessage('BAD_REQUEST'))
                ->toBe('Invalid request. Please check your agent configuration.');
        });

        it('returns correct message for UNKNOWN', function () {
            expect(friendlyMessage('UNKNOWN'))
                ->toBe('An unexpected error occurred. Please try again.');
        });

        it('falls back to UNKNOWN message for unknown codes', function () {
            expect(friendlyMessage('DOES_NOT_EXIST'))
                ->toBe('An unexpected error occurred. Please try again.');
        });
    });
});
