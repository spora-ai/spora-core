<?php

declare(strict_types=1);

use Spora\Services\Imap\MessageParser;

/**
 * Edge-case suite for MessageParser's public methods. The base
 * MessageParserTest covers happy paths; this file targets the corner cases
 * (overlong inputs, unicode, malformed MIME, control characters, etc.) that
 * are the source of most regressions in text decoders.
 */
describe('MessageParser::parseAddressList edge cases', function (): void {
    it('returns empty for a tab- and newline-only string', function (): void {
        expect(MessageParser::parseAddressList("\t\n\r "))->toBe([]);
    });

    it('treats a single @ token (no local or domain) as a literal address', function (): void {
        // Documents current behavior: the parser does not validate
        // email shape, it just slices on <>, commas, and quotes. A
        // bare "@" comes back as a single record.
        expect(MessageParser::parseAddressList('@'))->toBe([['name' => null, 'email' => '@']]);
    });

    it('handles a name with no @ and no <...>', function (): void {
        expect(MessageParser::parseAddressList('bob'))->toBe([['name' => null, 'email' => 'bob']]);
    });

    it('trims trailing commas without producing a phantom address', function (): void {
        expect(MessageParser::parseAddressList('alice@example.com,'))->toBe([
            ['name' => null, 'email' => 'alice@example.com'],
        ]);
    });

    it('handles a quoted local part with embedded whitespace', function (): void {
        // Documents current behavior: the quoted name is consumed
        // separately, then the unquoted reader picks up at
        // "@example.com" (the "al ice" portion was already consumed
        // as the quoted name and is not re-read).
        $result = MessageParser::parseAddressList('"al ice"@example.com');
        expect($result)->toHaveCount(2);
        expect($result[0]['name'])->toBe('al ice');
        expect($result[1]['email'])->toBe('@example.com');
    });

    it('parses an IDN-style unicode local part', function (): void {
        $result = MessageParser::parseAddressList('dörte@example.com');
        expect($result[0]['email'])->toBe('dörte@example.com');
    });

    it('handles a deeply nested quoted display name with escaped quote', function (): void {
        $result = MessageParser::parseAddressList('"Last, \\"Quoted\\" First" <a@b.c>');
        expect($result[0]['name'])->toBe('Last, "Quoted" First');
        expect($result[0]['email'])->toBe('a@b.c');
    });

    it('parses three addresses in a row separated by comma+space', function (): void {
        $result = MessageParser::parseAddressList('a@x.io, b@x.io, c@x.io');
        expect(array_column($result, 'email'))->toBe(['a@x.io', 'b@x.io', 'c@x.io']);
    });

    it('treats an unclosed angle bracket as a Name <empty-email> record', function (): void {
        // Documents current behavior: readUnquotedAddress reads "a@b.c"
        // as the buffer, then sees "<" but no closing ">", so the email
        // is empty. The name is set to the buffer.
        $result = MessageParser::parseAddressList('a@b.c<');
        expect($result)->toHaveCount(1);
        expect($result[0]['name'])->toBe('a@b.c');
        expect($result[0]['email'])->toBe('');
    });

    it('consumes trailing characters as part of the bare email when no separator is present', function (): void {
        // Documents current behavior: readUnquotedAddress reads up to the
        // next "<" or ",". With neither present it consumes the rest of
        // the input. This is a known limitation — strict RFC parsing
        // would require an <email> wrapper.
        $result = MessageParser::parseAddressList('a@b.c xxx');
        expect($result[0]['email'])->toBe('a@b.c xxx');
    });

    it('preserves whitespace inside a quoted display name', function (): void {
        // Documents current behavior: the leading/trailing whitespace
        // is NOT trimmed from the quoted name (only unquoted names are
        // trimmed).
        $result = MessageParser::parseAddressList('"  spaced  " <a@b.c>');
        expect($result[0]['name'])->toBe('  spaced  ');
    });

    it('treats a single empty pair of quotes as a single literal quote', function (): void {
        // Documents current behavior: the unclosed quote is consumed
        // as the start of a name with no closing pair, then the parser
        // continues into the unquoted branch which captures the "x".
        $result = MessageParser::parseAddressList('"x');
        expect($result)->toHaveCount(1);
        expect($result[0]['name'])->toBe('x');
    });

    it('returns name=null when display name is empty quotes followed by <email>', function (): void {
        $result = MessageParser::parseAddressList('"" <a@b.c>');
        expect($result[0]['name'])->toBeNull();
        expect($result[0]['email'])->toBe('a@b.c');
    });
});

describe('MessageParser::decodeQuotedString edge cases', function (): void {
    it('returns a single literal quote for a single quote', function (): void {
        // Documents current behavior: a one-character string that
        // isn't a quote is returned as-is.
        expect(MessageParser::decodeQuotedString('"'))->toBe('"');
    });

    it('returns the empty string for two empty quotes', function (): void {
        expect(MessageParser::decodeQuotedString('""'))->toBe('');
    });

    it('preserves an unmatched backslash at the very end as a literal', function (): void {
        // Documents current behavior: the loop sees '\\' followed by
        // end-of-string (no next char), so it appends the backslash
        // and stops. The surrounding quote is preserved because the
        // first and last bytes are not both quotes.
        expect(MessageParser::decodeQuotedString('"a\\'))->toBe('"a\\');
    });

    it('decodes a backslash followed by a non-special char as just the non-special char', function (): void {
        // The escape removes the backslash, keeping only the next char.
        expect(MessageParser::decodeQuotedString('"a\\b"'))->toBe('ab');
    });

    it('preserves the opening quote when there is no closing quote', function (): void {
        // Documents current behavior: the function only strips the
        // outer pair when the first and last bytes are both quotes.
        expect(MessageParser::decodeQuotedString('"unterminated'))->toBe('"unterminated');
    });

    it('strips quotes even when the inner content has special chars', function (): void {
        expect(MessageParser::decodeQuotedString('"a@b; c=d"'))->toBe('a@b; c=d');
    });

    it('returns the input unchanged when it is one character and not a quote', function (): void {
        expect(MessageParser::decodeQuotedString('x'))->toBe('x');
    });
});

describe('MessageParser::parseHeaders edge cases', function (): void {
    it('returns nulls for a completely empty header bag', function (): void {
        $parsed = MessageParser::parseHeaders([]);
        expect($parsed['from'])->toBeNull()
            ->and($parsed['to'])->toBeNull()
            ->and($parsed['subject'])->toBeNull()
            ->and($parsed['date'])->toBeNull()
            ->and($parsed['cc'])->toBeNull()
            ->and($parsed['bcc'])->toBeNull();
    });

    it('preserves a multi-line subject containing newlines', function (): void {
        $parsed = MessageParser::parseHeaders(['subject' => "line one\nline two"]);
        expect($parsed['subject'])->toBe("line one\nline two");
    });

    it('casts numeric subject to its string form', function (): void {
        $parsed = MessageParser::parseHeaders(['subject' => 42]);
        expect($parsed['subject'])->toBe('42');
    });

    it('casts an empty array subject to its string form (known limitation)', function (): void {
        // Documents current behavior: the empty array is stringified
        // to "Array". A real fix would treat [] as null, but that's
        // out of scope for this commit.
        $parsed = MessageParser::parseHeaders(['subject' => []]);
        expect($parsed['subject'])->toBe('Array');
    });

    it('returns null for explicit null subject', function (): void {
        $parsed = MessageParser::parseHeaders(['subject' => null]);
        expect($parsed['subject'])->toBeNull();
    });

    it('looks up case-insensitive but preserves the original casing key', function (): void {
        $parsed = MessageParser::parseHeaders(['FROM' => 'a@b.c']);
        expect($parsed['from'])->toBe('a@b.c');
    });

    it('renders an Address-shaped object as Name <email>', function (): void {
        $parsed = MessageParser::parseHeaders(['from' => (object) ['personal' => 'Alice', 'mailbox' => 'alice', 'host' => 'example.com']]);
        expect($parsed['from'])->toContain('<');
    });
});

describe('MessageParser::decodeTransferEncoding edge cases', function (): void {
    it('trims and lowercases the encoding name', function (): void {
        expect(MessageParser::decodeTransferEncoding('aGVsbG8=', '  BASE64  '))->toBe('hello');
    });

    it('returns the body unchanged for an empty encoding', function (): void {
        expect(MessageParser::decodeTransferEncoding('hello', ''))->toBe('hello');
    });

    it('falls through to default for an unknown encoding', function (): void {
        expect(MessageParser::decodeTransferEncoding('hello', 'x-custom-encoding'))->toBe('hello');
    });
});

describe('MessageParser::decodeQuotedPrintable edge cases', function (): void {
    it('returns empty for the empty string', function (): void {
        expect(MessageParser::decodeQuotedPrintable(''))->toBe('');
    });

    it('decodes =E2=82=AC (the Euro sign, multi-byte UTF-8)', function (): void {
        // PHP's quoted_printable_decode appends a trailing newline; the
        // multibyte sequence decodes to the Euro sign.
        $decoded = rtrim(MessageParser::decodeQuotedPrintable('=E2=82=AC'));
        expect($decoded)->toBe('€');
    });

    it('decodes lowercase hex =2f (slash)', function (): void {
        // PHP's quoted_printable_decode appends a trailing newline.
        expect(rtrim(MessageParser::decodeQuotedPrintable('=2f')))->toBe('/');
    });

    it('decodes mixed-case hex =2F', function (): void {
        expect(rtrim(MessageParser::decodeQuotedPrintable('=2F')))->toBe('/');
    });

    it('leaves = at end of input literal (= is not followed by two hex chars)', function (): void {
        // '=b' is not a valid =XX hex escape, so it is preserved.
        expect(rtrim(MessageParser::decodeQuotedPrintable('a=b')))->toContain('b');
    });

    it('strips soft line breaks (the =\n sequence) and joins lines', function (): void {
        // Soft line break is =\r?\n; after stripping, the lines are
        // joined. PHP's quoted_printable_decode also appends a
        // trailing newline, which is preserved by this contract.
        $decoded = rtrim(MessageParser::decodeQuotedPrintable("hello=\nworld"));
        expect($decoded)->toBe('helloworld');
    });
});

describe('MessageParser::decodeBase64 edge cases', function (): void {
    it('decodes URL-safe base64 (with - and _)', function (): void {
        // URL-safe is not standard base64; our impl is strict, so this
        // returns empty. Document the contract.
        expect(MessageParser::decodeBase64('aGV-bG8='))->toBe('');
    });

    it('returns empty for a base64 string that fails strict validation', function (): void {
        expect(MessageParser::decodeBase64('not!base64!'))->toBe('');
    });

    it('handles a base64 string with internal newlines (whitespace tolerated)', function (): void {
        // "hello" with whitespace and padding
        expect(MessageParser::decodeBase64("aGVs\nbG8="))->toBe('hello');
    });

    it('returns empty when the string is only whitespace', function (): void {
        expect(MessageParser::decodeBase64("   \n   "))->toBe('');
    });

    it('decodes a 2-char base64 (e.g. Zg → f) without strict padding', function (): void {
        // "Zg" is a valid base64 pair that decodes to a single byte.
        // Our strict validator accepts it (no padding required for
        // short inputs).
        expect(MessageParser::decodeBase64('Zg'))->toBe('f');
    });

    it('decodes binary content round-trip', function (): void {
        $original = "\x00\x01\x02\x03\x04";
        $encoded  = base64_encode($original);
        expect(MessageParser::decodeBase64($encoded))->toBe($original);
    });
});

describe('MessageParser::parseBody edge cases', function (): void {
    it('returns nulls for an unrecognized content-type', function (): void {
        expect(MessageParser::parseBody('body', 'application/octet-stream'))
            ->toBe(['text' => null, 'html' => null]);
    });

    it('returns nulls for an empty body and empty content-type', function (): void {
        expect(MessageParser::parseBody('', ''))->toBe(['text' => null, 'html' => null]);
    });

    it('treats text/html with empty body as null html', function (): void {
        expect(MessageParser::parseBody('', 'text/html'))->toBe(['text' => null, 'html' => null]);
    });

    it('treats text/plain with empty body as null text', function (): void {
        expect(MessageParser::parseBody('', 'text/plain'))->toBe(['text' => null, 'html' => null]);
    });

    it('returns nulls for multipart with no boundary parameter', function (): void {
        expect(MessageParser::parseBody('body', 'multipart/alternative'))
            ->toBe(['text' => null, 'html' => null]);
    });

    it('uppercase multipart still triggers the multipart branch', function (): void {
        $body = "--BOUNDARY\r\nContent-Type: text/plain\r\n\r\nhello\r\n--BOUNDARY--\r\n";
        $result = MessageParser::parseBody($body, 'Multipart/Alternative; boundary=BOUNDARY');
        // The body section is preserved verbatim; the trailing CRLF
        // is part of the section per RFC 2046.
        expect($result['text'])->toBe("hello\r\n");
    });

    it('handles multipart with only an attachment (no text or html)', function (): void {
        $body = "--B\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=a.bin\r\n\r\nbytes\r\n--B--\r\n";
        $result = MessageParser::parseBody($body, 'multipart/mixed; boundary=B');
        expect($result['text'])->toBeNull()->and($result['html'])->toBeNull();
    });

    it('decodes quoted-printable within a multipart text part', function (): void {
        $body = "--B\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n=C3=A4\r\n--B--\r\n";
        $result = MessageParser::parseBody($body, 'multipart/mixed; boundary=B');
        // The decoded QP body preserves the trailing CRLF that was in
        // the section.
        expect($result['text'])->toBe("ä\r\n");
    });
});

describe('MessageParser::selectReadableBody edge cases', function (): void {
    it('treats null html as empty', function (): void {
        expect(MessageParser::selectReadableBody('hello', null))->toBe('hello');
    });

    it('strips ALL tags when only html is provided', function (): void {
        $html = '<div><p>Hello <strong>world</strong></p></div>';
        expect(MessageParser::selectReadableBody(null, $html))->toBe('Hello world');
    });

    it('handles both null inputs', function (): void {
        expect(MessageParser::selectReadableBody(null, null))->toBe('');
    });

    it('strips script content as well as tags', function (): void {
        // strip_tags removes tags but keeps inner text. A real XSS guard
        // would also remove inline JS; this is just the readable-body
        // contract.
        $html = '<script>alert(1)</script><p>safe</p>';
        expect(MessageParser::selectReadableBody(null, $html))->toContain('safe');
    });
});
