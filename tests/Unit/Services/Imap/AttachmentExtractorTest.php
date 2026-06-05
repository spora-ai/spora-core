<?php

declare(strict_types=1);

use Spora\Services\Imap\AttachmentExtractor;

describe('AttachmentExtractor::extract', function (): void {

    it('returns an empty list for no parts', function (): void {
        expect(AttachmentExtractor::extract([]))->toBe([]);
    });

    it('skips parts without a Content-Disposition', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type' => 'image/png',
                ],
                'body' => 'bytes',
            ],
        ];
        expect(AttachmentExtractor::extract($parts))->toBe([]);
    });

    it('skips parts whose disposition is not "attachment"', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'inline',
                ],
                'body' => 'bytes',
            ],
        ];
        expect(AttachmentExtractor::extract($parts))->toBe([]);
    });

    it('skips text/* parts even when they have name= or are flagged attachment', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'text/plain; name=note.txt',
                    'content-disposition' => 'attachment; filename=note.txt',
                ],
                'body' => 'plain text body',
            ],
        ];
        expect(AttachmentExtractor::extract($parts))->toBe([]);
    });

    it('captures filename from Content-Disposition', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment; filename="hello.png"',
                ],
                'body' => 'png-bytes',
            ],
        ];
        $atts = AttachmentExtractor::extract($parts);
        expect($atts)->toHaveCount(1);
        expect($atts[0]['filename'])->toBe('hello.png');
    });

    it('falls back to name= in Content-Type when filename= is absent', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png; name="fallback.png"',
                    'content-disposition' => 'attachment',
                ],
                'body' => 'bytes',
            ],
        ];
        expect(AttachmentExtractor::extract($parts)[0]['filename'])->toBe('fallback.png');
    });

    it('returns null filename when neither filename= nor name= is set', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment',
                ],
                'body' => 'bytes',
            ],
        ];
        expect(AttachmentExtractor::extract($parts)[0]['filename'])->toBeNull();
    });

    it('lowercases the content-type', function (): void {
        $parts = [
            [
                'headers' => [
                    'Content-Type'        => 'IMAGE/PNG',
                    'Content-Disposition' => 'ATTACHMENT; filename="x.png"',
                ],
                'body' => 'bytes',
            ],
        ];
        $atts = AttachmentExtractor::extract($parts);
        expect($atts[0]['content_type'])->toBe('image/png');
    });

    it('strips angle brackets from Content-Id', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment; filename="x.png"',
                    'content-id'          => '<img-42@example.com>',
                ],
                'body' => 'bytes',
            ],
        ];
        expect(AttachmentExtractor::extract($parts)[0]['content_id'])->toBe('img-42@example.com');
    });

    it('finds Content-Id case-insensitively', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment; filename="x.png"',
                    'Content-ID'          => '<img@example.com>',
                ],
                'body' => 'bytes',
            ],
        ];
        expect(AttachmentExtractor::extract($parts)[0]['content_id'])->toBe('img@example.com');
    });

    it('returns null content_id when the header is missing', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment',
                ],
                'body' => 'bytes',
            ],
        ];
        expect(AttachmentExtractor::extract($parts)[0]['content_id'])->toBeNull();
    });

    it('measures body size in bytes', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'application/octet-stream',
                    'content-disposition' => 'attachment; filename="blob.bin"',
                ],
                'body' => str_repeat('a', 1024),
            ],
        ];
        expect(AttachmentExtractor::extract($parts)[0]['size'])->toBe(1024);
    });

    it('always sets disposition to "attachment" on emitted records', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment; filename="x.png"',
                ],
                'body' => 'bytes',
            ],
        ];
        expect(AttachmentExtractor::extract($parts)[0]['disposition'])->toBe('attachment');
    });

    it('extracts multiple attachments and preserves their order', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment; filename="a.png"',
                ],
                'body' => 'a',
            ],
            [
                'headers' => [
                    'content-type'        => 'application/pdf',
                    'content-disposition' => 'attachment; filename="b.pdf"',
                ],
                'body' => 'b',
            ],
            [
                'headers' => [
                    'content-type'        => 'image/jpeg',
                    'content-disposition' => 'inline',
                ],
                'body' => 'c',
            ],
        ];
        $atts = AttachmentExtractor::extract($parts);
        expect($atts)->toHaveCount(2);
        expect(array_column($atts, 'filename'))->toBe(['a.png', 'b.pdf']);
    });

    it('handles a part with no headers key at all', function (): void {
        $parts = [['body' => 'bytes']];
        expect(AttachmentExtractor::extract($parts))->toBe([]);
    });

    it('handles a part with completely empty headers', function (): void {
        $parts = [['headers' => [], 'body' => 'bytes']];
        expect(AttachmentExtractor::extract($parts))->toBe([]);
    });

    it('treats a missing body key as empty body (size 0)', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'application/octet-stream',
                    'content-disposition' => 'attachment; filename="empty.bin"',
                ],
            ],
        ];
        $atts = AttachmentExtractor::extract($parts);
        expect($atts[0]['size'])->toBe(0);
    });

    it('matches a disposition containing "attachment" anywhere in its value', function (): void {
        // RFC 2183 allows parameter ordering variations; the part is still
        // an attachment if the token "attachment" appears in the disposition.
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'application/pdf',
                    'content-disposition' => 'inline; attachment',  // odd but possible
                ],
                'body' => 'pdf',
            ],
        ];
        // "attachment" appears in the disposition string, so it's treated as
        // an attachment per the "contains 'attachment'" check.
        expect(AttachmentExtractor::extract($parts))->toHaveCount(1);
    });
});
