<?php

declare(strict_types=1);

use Spora\Tools\ValueObjects\ToolResult;

describe('ToolResult', function () {
    describe('ok', function () {
        it('builds a successful result with the given content', function () {
            $result = ToolResult::ok('hello');

            expect($result->success)->toBeTrue()
                ->and($result->content)->toBe('hello')
                ->and($result->data)->toBeNull();
        });

        it('attaches the given data payload when provided', function () {
            $data = ['uid' => 7, 'folder' => 'INBOX'];

            $result = ToolResult::ok('found 1 email', $data);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toBe('found 1 email')
                ->and($result->data)->toBe($data);
        });
    });

    describe('fail', function () {
        it('builds a failed result with the given error message', function () {
            $result = ToolResult::fail('Missing required parameter: uid');

            expect($result->success)->toBeFalse()
                ->and($result->content)->toBe('Missing required parameter: uid')
                ->and($result->data)->toBeNull();
        });
    });
});
