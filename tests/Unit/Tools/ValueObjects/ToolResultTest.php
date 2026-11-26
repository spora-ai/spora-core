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

        it('carries the archive attachments the result points at', function () {
            $attachments = [['media_id' => 'asset-1', 'kind' => 'image']];

            $result = ToolResult::ok('the image is attached', null, $attachments);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toBe('the image is attached')
                ->and($result->attachments)->toBe($attachments);
        });

        it('attaches no archive references when none are given', function () {
            // The two calls every plugin in the tree already makes.
            expect(ToolResult::ok('hello')->attachments)->toBe([])
                ->and(ToolResult::ok('hello', ['uid' => 7])->attachments)->toBe([]);
        });
    });

    describe('fail', function () {
        it('builds a failed result with the given error message', function () {
            $result = ToolResult::fail('Missing required parameter: uid');

            expect($result->success)->toBeFalse()
                ->and($result->content)->toBe('Missing required parameter: uid')
                ->and($result->data)->toBeNull()
                ->and($result->attachments)->toBe([]);
        });
    });

    describe('the constructor', function () {
        it('still builds a result for a caller that does not use the factory', function () {
            // The direct construction predates the channel and every plugin
            // may still use it.
            $result = new ToolResult(true, 'done', ['uid' => 7]);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toBe('done')
                ->and($result->data)->toBe(['uid' => 7])
                ->and($result->attachments)->toBe([]);
        });

        it('carries the attachments when they are passed', function () {
            $attachments = [['media_id' => 'asset-1', 'kind' => 'image']];

            $result = new ToolResult(true, 'done', null, $attachments);

            expect($result->attachments)->toBe($attachments);
        });
    });
});
