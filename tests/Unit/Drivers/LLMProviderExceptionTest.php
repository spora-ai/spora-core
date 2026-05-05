<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Spora\Drivers\Exceptions\LLMProviderException;

describe('LLMProviderException', function () {

    describe('isRetryable()', function () {
        it('returns true for 502 Bad Gateway', function () {
            $e = new LLMProviderException('OpenAI API error 502: Bad Gateway');
            expect($e->isRetryable())->toBe(true);
        });

        it('returns true for 503 Service Unavailable', function () {
            $e = new LLMProviderException('Anthropic API error 503: Service Unavailable');
            expect($e->isRetryable())->toBe(true);
        });

        it('returns true for 504 Gateway Timeout', function () {
            $e = new LLMProviderException('Gateway timeout 504');
            expect($e->isRetryable())->toBe(true);
        });

        it('returns true for 520 Unknown Error', function () {
            $e = new LLMProviderException('OpenAI API error 520: Unknown');
            expect($e->isRetryable())->toBe(true);
        });

        it('returns true for 529 Server Overloaded', function () {
            $e = new LLMProviderException('Server overloaded 529');
            expect($e->isRetryable())->toBe(true);
        });

        it('returns true for 429 Rate Limit', function () {
            $e = new LLMProviderException('Rate limit exceeded 429');
            expect($e->isRetryable())->toBe(true);
        });

        it('returns false for 400 Bad Request', function () {
            $e = new LLMProviderException('Bad request 400');
            expect($e->isRetryable())->toBe(false);
        });

        it('returns false for 401 Unauthorized', function () {
            $e = new LLMProviderException('Unauthorized 401');
            expect($e->isRetryable())->toBe(false);
        });

        it('returns false for context window errors', function () {
            $e = new LLMProviderException('context window exceeds limit 2013');
            expect($e->isRetryable())->toBe(false);
        });
    });

    describe('getActualContextWindow()', function () {
        it('returns null when no context window was parsed', function () {
            $e = new LLMProviderException('Some error');
            expect($e->getActualContextWindow())->toBeNull();
        });

        it('returns the parsed context window from constructor', function () {
            $e = new LLMProviderException('error', 0, null, 200000);
            expect($e->getActualContextWindow())->toBe(200000);
        });
    });

    describe('getErrorType()', function () {
        it('returns null when no error type was set', function () {
            $e = new LLMProviderException('Some error');
            expect($e->getErrorType())->toBeNull();
        });

        it('returns the error type from constructor', function () {
            $e = new LLMProviderException('error', 0, null, null, 'invalid_request_error');
            expect($e->getErrorType())->toBe('invalid_request_error');
        });
    });

    describe('withParsedError()', function () {
        it('updates context window from parsed error body', function () {
            $e = new LLMProviderException('context window exceeds limit (128000)');
            $updated = $e->withParsedError('{"error":{"type":"context_window_exceeded","message":"limit","max_context_window":128000}}');

            expect($updated->getActualContextWindow())->toBe(128000);
            expect($updated->getErrorType())->toBe('context_window_exceeded');
        });

        it('preserves existing values when parsing fails', function () {
            $e = new LLMProviderException('original error', 0, null, 50000, 'original_type');
            $updated = $e->withParsedError('not json at all');

            expect($updated->getActualContextWindow())->toBe(50000);
            expect($updated->getErrorType())->toBe('original_type');
        });

        it('extracts error type from JSON', function () {
            $e = new LLMProviderException('error');
            $updated = $e->withParsedError('{"type":"invalid_request_error","message":"too long"}');

            expect($updated->getErrorType())->toBe('invalid_request_error');
        });
    });
});
