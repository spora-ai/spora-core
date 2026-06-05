<?php

declare(strict_types=1);

use Spora\Services\Imap\MessageParser;

describe('MessageParser::parseAddressList', function (): void {

    it('parses a single bare email', function (): void {
        $addrs = MessageParser::parseAddressList('user@example.com');
        expect($addrs)->toBe([['name' => null, 'email' => 'user@example.com']]);
    });

    it('parses a named address', function (): void {
        $addrs = MessageParser::parseAddressList('John Doe <john@example.com>');
        expect($addrs)->toBe([['name' => 'John Doe', 'email' => 'john@example.com']]);
    });

    it('parses multiple addresses separated by commas', function (): void {
        $addrs = MessageParser::parseAddressList('a@x.com, b@x.com, c@x.com');
        expect($addrs)->toBe([
            ['name' => null, 'email' => 'a@x.com'],
            ['name' => null, 'email' => 'b@x.com'],
            ['name' => null, 'email' => 'c@x.com'],
        ]);
    });

    it('parses multiple named addresses', function (): void {
        $addrs = MessageParser::parseAddressList('Alice <a@x.com>, Bob <b@x.com>');
        expect($addrs)->toBe([
            ['name' => 'Alice', 'email' => 'a@x.com'],
            ['name' => 'Bob',   'email' => 'b@x.com'],
        ]);
    });

    it('parses a quoted name containing a comma', function (): void {
        $addrs = MessageParser::parseAddressList('"Doe, John" <john@example.com>');
        expect($addrs)->toBe([['name' => 'Doe, John', 'email' => 'john@example.com']]);
    });

    it('returns empty for an empty string', function (): void {
        expect(MessageParser::parseAddressList(''))->toBe([]);
    });

    it('returns empty for whitespace only', function (): void {
        expect(MessageParser::parseAddressList("   \n  "))->toBe([]);
    });

    it('parses a quoted name with backslash-escaped quote', function (): void {
        $addrs = MessageParser::parseAddressList('"O\"Connor" <oconnor@example.com>');
        expect($addrs[0]['name'])->toBe('O"Connor');
        expect($addrs[0]['email'])->toBe('oconnor@example.com');
    });
});

describe('MessageParser::decodeQuotedString', function (): void {

    it('strips surrounding double quotes', function (): void {
        expect(MessageParser::decodeQuotedString('"hello"'))->toBe('hello');
    });

    it('decodes backslash escapes', function (): void {
        expect(MessageParser::decodeQuotedString('"a\\"b"'))->toBe('a"b');
    });

    it('returns unquoted string unchanged', function (): void {
        expect(MessageParser::decodeQuotedString('plain'))->toBe('plain');
    });
});

describe('MessageParser::parseHeaders', function (): void {

    it('extracts From/To/Subject/Date/Cc/Bcc from a header bag', function (): void {
        $from = (object) ['mail' => 'sender@x.com', 'personal' => 'Sender'];
        $to1  = (object) ['mail' => 'a@x.com', 'personal' => 'A'];
        $to2  = (object) ['mail' => 'b@x.com', 'personal' => 'B'];

        $headers = [
            'from'    => $from,
            'to'      => [$to1, $to2],
            'cc'      => 'c@x.com',
            'bcc'     => '',
            'subject' => 'Hi there',
            'date'    => '2025-01-01 12:00:00',
        ];

        $parsed = MessageParser::parseHeaders($headers);

        expect($parsed['from'])->toBe('Sender <sender@x.com>');
        expect($parsed['to'])->toBe('A <a@x.com>, B <b@x.com>');
        expect($parsed['cc'])->toBe('c@x.com');
        expect($parsed['bcc'])->toBeNull();
        expect($parsed['subject'])->toBe('Hi there');
        expect($parsed['date'])->toBe('2025-01-01 12:00:00');
    });

    it('returns nulls for empty/missing fields', function (): void {
        $parsed = MessageParser::parseHeaders([]);
        expect($parsed)->toBe([
            'from'    => null,
            'to'      => null,
            'subject' => null,
            'date'    => null,
            'cc'      => null,
            'bcc'     => null,
        ]);
    });
});

describe('MessageParser::decodeTransferEncoding', function (): void {

    it('decodes quoted-printable', function (): void {
        $encoded = "Hello=20world=21";
        expect(MessageParser::decodeTransferEncoding($encoded, 'quoted-printable'))->toBe('Hello world!');
    });

    it('decodes base64', function (): void {
        expect(MessageParser::decodeTransferEncoding(base64_encode('Hello world'), 'base64'))->toBe('Hello world');
    });

    it('returns the body unchanged for 7bit', function (): void {
        expect(MessageParser::decodeTransferEncoding('Hello', '7bit'))->toBe('Hello');
    });

    it('returns the body unchanged for 8bit', function (): void {
        expect(MessageParser::decodeTransferEncoding('Hello', '8bit'))->toBe('Hello');
    });
});

describe('MessageParser::decodeQuotedPrintable', function (): void {

    it('decodes =XX hex sequences', function (): void {
        expect(MessageParser::decodeQuotedPrintable('Caf=C3=A9'))->toBe('Café');
    });

    it('strips soft line breaks (=\\n)', function (): void {
        $withSoft = "Hello=\nWorld";
        expect(MessageParser::decodeQuotedPrintable($withSoft))->toBe('HelloWorld');
    });
});

describe('MessageParser::decodeBase64', function (): void {

    it('decodes padded base64', function (): void {
        expect(MessageParser::decodeBase64('SGVsbG8='))->toBe('Hello');
    });

    it('decodes unpadded base64', function (): void {
        expect(MessageParser::decodeBase64('SGVsbG8'))->toBe('Hello');
    });

    it('tolerates whitespace inside the input', function (): void {
        expect(MessageParser::decodeBase64("SGVs\nbG8="))->toBe('Hello');
    });

    it('returns empty string for invalid base64', function (): void {
        expect(MessageParser::decodeBase64('!!!not_base64!!!'))->toBe('');
    });
});

describe('MessageParser::parseBody', function (): void {

    it('returns empty parts for empty body', function (): void {
        $out = MessageParser::parseBody('', 'text/plain');
        expect($out)->toBe(['text' => null, 'html' => null]);
    });

    it('returns text part for text/plain', function (): void {
        $out = MessageParser::parseBody('Hello world', 'text/plain; charset=utf-8');
        expect($out['text'])->toBe('Hello world');
        expect($out['html'])->toBeNull();
    });

    it('returns html part for text/html', function (): void {
        $out = MessageParser::parseBody('<p>Hello</p>', 'text/html; charset=utf-8');
        expect($out['text'])->toBeNull();
        expect($out['html'])->toBe('<p>Hello</p>');
    });

    it('extracts both text and html from multipart/alternative', function (): void {
        $boundary = 'boundary42';
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n\r\n"
            . "Plain text version\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n\r\n"
            . "<p>HTML version</p>\r\n"
            . "--{$boundary}--\r\n";

        $ct = "multipart/alternative; boundary=\"{$boundary}\"";
        $out = MessageParser::parseBody($body, $ct);

        // MIME parts are terminated by a CRLF before the next boundary; the parser
        // preserves that trailing newline as part of the decoded body.
        expect($out['text'])->toBe("Plain text version\r\n");
        expect($out['html'])->toBe("<p>HTML version</p>\r\n");
    });

    it('prefers text part over html in multipart/alternative', function (): void {
        $boundary = 'b';
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/html\r\n\r\n"
            . "<p>only html</p>\r\n"
            . "--{$boundary}--\r\n";

        $ct = "multipart/alternative; boundary={$boundary}";
        $out = MessageParser::parseBody($body, $ct);
        expect($out['text'])->toBeNull();
        expect($out['html'])->toBe("<p>only html</p>\r\n");
    });

    it('handles multipart/related with attachment parts', function (): void {
        $boundary = 'mix';
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . "Hi there\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Disposition: attachment; filename=\"hello.txt\"\r\n\r\n"
            . "file contents\r\n"
            . "--{$boundary}--\r\n";

        $ct = "multipart/mixed; boundary={$boundary}";
        $out = MessageParser::parseBody($body, $ct);

        expect($out['text'])->toBe("Hi there\r\n");
        expect($out['html'])->toBeNull();
    });
});

describe('MessageParser::selectReadableBody', function (): void {

    it('returns the text body when not empty', function (): void {
        expect(MessageParser::selectReadableBody('hello', '<p>hi</p>'))->toBe('hello');
    });

    it('returns the html body (tag-stripped) when text is empty', function (): void {
        expect(MessageParser::selectReadableBody('', '<p>hi</p>'))->toBe('hi');
    });

    it('returns empty string when both are empty', function (): void {
        expect(MessageParser::selectReadableBody('', null))->toBe('');
    });

    it('treats whitespace-only text as empty and falls back to html', function (): void {
        expect(MessageParser::selectReadableBody("   \n  ", '<p>hi</p>'))->toBe('hi');
    });
});

describe('MessageParser::extractAttachments', function (): void {

    it('returns empty when there are no parts', function (): void {
        expect(MessageParser::extractAttachments([]))->toBe([]);
    });

    it('detects attachment disposition', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'application/octet-stream',
                    'content-disposition' => 'attachment; filename="hello.txt"',
                ],
                'body' => 'file contents',
            ],
        ];

        $atts = MessageParser::extractAttachments($parts);
        expect($atts)->toHaveCount(1);
        expect($atts[0]['filename'])->toBe('hello.txt');
        expect($atts[0]['size'])->toBe(13);
    });

    it('does NOT flag inline parts as attachments', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'text/plain',
                    'content-disposition' => 'inline',
                ],
                'body' => 'inline text',
            ],
        ];
        expect(MessageParser::extractAttachments($parts))->toBe([]);
    });

    it('skips text parts even when they have name=', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'text/plain; name=note.txt',
                    'content-disposition' => 'inline',
                ],
                'body' => 'hi',
            ],
        ];
        expect(MessageParser::extractAttachments($parts))->toBe([]);
    });

    it('extracts multiple attachments', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment; filename="a.png"',
                ],
                'body' => 'png-bytes',
            ],
            [
                'headers' => [
                    'content-type'        => 'application/pdf',
                    'content-disposition' => 'attachment; filename="b.pdf"',
                ],
                'body' => 'pdf-bytes',
            ],
        ];
        $atts = MessageParser::extractAttachments($parts);
        expect($atts)->toHaveCount(2);
        expect(array_column($atts, 'filename'))->toBe(['a.png', 'b.pdf']);
    });

    it('handles capitalized header keys (Content-Type, Content-Disposition)', function (): void {
        $parts = [
            [
                'headers' => [
                    'Content-Type'        => 'image/jpeg',
                    'Content-Disposition' => 'attachment; filename="pic.jpg"',
                ],
                'body' => 'jpeg-bytes',
            ],
        ];
        $atts = MessageParser::extractAttachments($parts);
        expect($atts)->toHaveCount(1);
        expect($atts[0]['filename'])->toBe('pic.jpg');
        expect($atts[0]['content_type'])->toBe('image/jpeg');
    });

    it('strips angle brackets from Content-ID (capitalized)', function (): void {
        $parts = [
            [
                'headers' => [
                    'content-type'        => 'image/png',
                    'content-disposition' => 'attachment; filename="img.png"',
                    'Content-ID'          => '<img-42@example.com>',
                ],
                'body' => 'bytes',
            ],
        ];
        $atts = MessageParser::extractAttachments($parts);
        expect($atts[0]['content_id'])->toBe('img-42@example.com');
    });

    it('falls back to name= in content-type when filename= is absent', function (): void {
        $png = 'image/png; name="fallback.png"';
        $parts = [
            [
                'headers' => [
                    'content-type'        => $png,
                    'content-disposition' => 'attachment',
                ],
                'body' => 'bytes',
            ],
        ];
        $atts = MessageParser::extractAttachments($parts);
        expect($atts[0]['filename'])->toBe('fallback.png');
    });

    it('returns null filename when neither filename= nor name= is set', function (): void {
        $png = 'image/png';
        $parts = [
            [
                'headers' => [
                    'content-type'        => $png,
                    'content-disposition' => 'attachment',
                ],
                'body' => 'bytes',
            ],
        ];
        $atts = MessageParser::extractAttachments($parts);
        expect($atts[0]['filename'])->toBeNull();
    });
});
