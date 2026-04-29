<?php

declare(strict_types=1);

namespace Spora\Tests\Unit;

use Spora\Services\ContextWindowErrorParser;

describe('ContextWindowErrorParser', function () {

    describe('isContextWindowError()', function () {
        it('returns true for MiniMax context window error', function () {
            $body = '{"type":"error","error":{"type":"invalid_request_error","message":"context window exceeds limit (2013)"}}';
            expect((new ContextWindowErrorParser())->isContextWindowError($body))->toBe(true);
        });

        it('returns true for OpenAI context_window exceeded', function () {
            $body = '{"error":{"message":"context_window exceeded","type":"invalid_request_error"}}';
            expect((new ContextWindowErrorParser())->isContextWindowError($body))->toBe(true);
        });

        it('returns true for token limit exceeded', function () {
            $body = '{"error":{"message":"Maximum tokens limit exceeded"}}';
            expect((new ContextWindowErrorParser())->isContextWindowError($body))->toBe(true);
        });

        it('returns true for input too long', function () {
            $body = '{"error":{"message":"Input too long for model"}}';
            expect((new ContextWindowErrorParser())->isContextWindowError($body))->toBe(true);
        });

        it('returns true for context limit in message', function () {
            $body = '{"message":"maximum context window reached"}';
            expect((new ContextWindowErrorParser())->isContextWindowError($body))->toBe(true);
        });

        it('returns false for authentication errors', function () {
            $body = '{"error":{"message":"Invalid API key","type":"authentication_error"}}';
            expect((new ContextWindowErrorParser())->isContextWindowError($body))->toBe(false);
        });

        it('returns false for rate limit errors', function () {
            $body = '{"error":{"message":"Rate limit exceeded","type":"rate_limit_error"}}';
            expect((new ContextWindowErrorParser())->isContextWindowError($body))->toBe(false);
        });

        it('returns false for server errors', function () {
            $body = '{"error":{"message":"Internal server error","type":"server_error"}}';
            expect((new ContextWindowErrorParser())->isContextWindowError($body))->toBe(false);
        });
    });

    describe('parse()', function () {
        it('extracts context window from max_context_window field', function () {
            $body = '{"error":{"type":"invalid_request_error","message":"limit exceeded","max_context_window":128000}}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['actual_context_window'])->toBe(128000);
        });

        it('extracts context window from error.context_window field', function () {
            $body = '{"error":{"type":"invalid_request_error","context_window":200000}}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['actual_context_window'])->toBe(200000);
        });

        it('extracts context window from top-level limit field', function () {
            $body = '{"error":"limit exceeded","limit":243000}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['actual_context_window'])->toBe(243000);
        });

        it('extracts context window from message string with numeric limit pattern', function () {
            $body = '{"error":{"type":"invalid_request_error","message":"context window exceeds limit (2013)"}}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['actual_context_window'])->toBe(2013);
        });

        it('extracts context window from limit() pattern in message', function () {
            $body = '{"message":"Maximum context window reached. Limit: 100000 tokens"}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['actual_context_window'])->toBe(100000);
        });

        it('extracts error type from type field', function () {
            $body = '{"type":"invalid_request_error","message":"too long"}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['error_type'])->toBe('invalid_request_error');
        });

        it('extracts error type from error.type field', function () {
            $body = '{"error":{"type":"context_window_exceeded","message":"limit"}}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['error_type'])->toBe('context_window_exceeded');
        });

        it('returns null for actual_context_window when not found', function () {
            $body = '{"error":{"message":"Something went wrong"}}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['actual_context_window'])->toBeNull();
        });

        it('returns raw string as message when JSON parsing fails', function () {
            $body = 'not valid json at all';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['message'])->toBe('not valid json at all');
            expect($result['actual_context_window'])->toBeNull();
        });

        it('extracts message from nested error.message field', function () {
            $body = '{"error":{"message":"context window exceeded"}}';
            $result = (new ContextWindowErrorParser())->parse($body);
            expect($result['message'])->toBe('context window exceeded');
        });
    });
});
