<?php

declare(strict_types=1);

namespace Spora\Services\Imap;

/**
 * Pure-function helpers for parsing raw IMAP message payloads into the
 * normalized shape that the rest of the application consumes.
 *
 * These helpers exist so that the network-bound ImapClient can be unit-tested
 * without an IMAP server: the parsers are pure functions of their input.
 */
final class MessageParser
{
    private const HTML_TYPE = 'text/html';
    private const PLAIN_TYPE = 'text/plain';


    /**
     * Parse an RFC 5322 address-list string into a list of [name, email] pairs.
     *
     * Handles:
     *   - "alice@example.com"
     *   - "Alice <alice@example.com>"
     *   - "alice@example.com, Bob <bob@example.com>"
     *   - "\"Last, First\" <first.last@example.com>"
     *
     * `name` is null when no display name is present.
     *
     * @return list<array{name: ?string, email: string}>
     */
    public static function parseAddressList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $result = [];
        $len = strlen($raw);
        $i = 0;
        while ($i < $len) {
            // Skip leading whitespace and commas between addresses.
            while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === ',' || $raw[$i] === "\t" || $raw[$i] === "\n" || $raw[$i] === "\r")) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            if ($raw[$i] === '"') {
                [$name, $email] = self::readQuotedAddress($raw, $i, $len);
            } elseif ($raw[$i] === '<') {
                $name = null;
                $email = self::readAngleAddress($raw, $i, $len);
            } else {
                [$name, $email] = self::readUnquotedAddress($raw, $i, $len);
            }

            if ($email !== null || $name !== null) {
                $result[] = ['name' => $name, 'email' => (string) $email];
            }
        }

        return $result;
    }

    /**
     * Parse a `"Display Name" <email>` pair. Advances $i past the closing `>`.
     *
     * @return array{0: ?string, 1: ?string} [name, email]
     */
    private static function readQuotedAddress(string $raw, int &$i, int $len): array
    {
        $i++; // skip opening quote
        $nameBuf = '';
        while ($i < $len && $raw[$i] !== '"') {
            if ($raw[$i] === '\\' && $i + 1 < $len) {
                $nameBuf .= $raw[$i + 1];
                $i += 2;
                continue;
            }
            $nameBuf .= $raw[$i];
            $i++;
        }
        if ($i < $len) {
            $i++;
        }
        $name = $nameBuf !== '' ? $nameBuf : null;

        while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === "\t")) {
            $i++;
        }
        $email = ($i < $len && $raw[$i] === '<') ? self::readAngleAddress($raw, $i, $len) : null;

        return [$name, $email];
    }

    /**
     * Read the contents of `<...>`, advancing $i past the closing `>`.
     */
    private static function readAngleAddress(string $raw, int &$i, int $len): string
    {
        $i++; // skip opening '<'
        $buf = '';
        while ($i < $len && $raw[$i] !== '>') {
            $buf .= $raw[$i];
            $i++;
        }
        if ($i < $len) {
            $i++;
        }
        return $buf;
    }

    /**
     * Parse an unquoted `Name <email>` or bare `email`. Advances $i past the
     * closing `>` when an angle address follows, otherwise to the next `,`.
     *
     * @return array{0: ?string, 1: ?string} [name, email]
     */
    private static function readUnquotedAddress(string $raw, int &$i, int $len): array
    {
        $buf = '';
        while ($i < $len && $raw[$i] !== ',' && $raw[$i] !== '<') {
            $buf .= $raw[$i];
            $i++;
        }
        $buf = trim($buf);
        // Re-read via substr() so PHPStan doesn't narrow the type from the loop's comparison.
        $next = $i < $len ? substr($raw, $i, 1) : '';
        if ($next === '<') {
            return [$buf !== '' ? $buf : null, self::readAngleAddress($raw, $i, $len)];
        }
        return [null, $buf !== '' ? $buf : null];
    }

    /**
     * Decode a double-quoted RFC 5322 string. Strips the surrounding quotes
     * and unescapes backslash escapes (\" → ", \\ → \).
     */
    public static function decodeQuotedString(string $s): string
    {
        $len = strlen($s);
        if ($len >= 2 && $s[0] === '"' && $s[$len - 1] === '"') {
            $inner = substr($s, 1, $len - 2);
        } else {
            $inner = $s;
        }

        $result = '';
        $i = 0;
        $innerLen = strlen($inner);
        while ($i < $innerLen) {
            if ($inner[$i] === '\\' && $i + 1 < $innerLen) {
                $result .= $inner[$i + 1];
                $i += 2;
                continue;
            }
            $result .= $inner[$i];
            $i++;
        }
        return $result;
    }

    /**
     * Extract a normalized header bag from a webklex-style header map.
     *
     * Each address-shaped field is rendered as a string in the form
     * "Name <email>" (or a comma-separated list of those for multi-valued
     * fields). Returns null when the field is missing.
     *
     * @param array<string, mixed> $headers
     * @return array{
     *     from: ?string, to: ?string, cc: ?string, bcc: ?string,
     *     subject: ?string, date: ?string
     * }
     */
    public static function parseHeaders(array $headers): array
    {
        $subject = self::findHeader($headers, 'subject');
        $date    = self::findHeader($headers, 'date');
        return [
            'from'    => self::renderHeader(self::findHeader($headers, 'from')),
            'to'      => self::renderHeader(self::findHeader($headers, 'to')),
            'subject' => $subject !== null ? (string) $subject : null,
            'date'    => $date !== null ? (string) $date : null,
            'cc'      => self::renderHeader(self::findHeader($headers, 'cc')),
            'bcc'     => self::renderHeader(self::findHeader($headers, 'bcc')),
        ];
    }

    /**
     * Case-insensitive header lookup. Webklex sometimes returns keys in their
     * original casing (`From`) and sometimes lowercased (`from`); the bag
     * this method receives is normalized upstream but the case-insensitive
     * search is preserved for safety.
     *
     * @param array<string, mixed> $headers
     */
    private static function findHeader(array $headers, string $key): mixed
    {
        if (array_key_exists($key, $headers)) {
            return $headers[$key];
        }
        $lower = strtolower($key);
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $lower) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Render a header value as a `Name <email>` string. Accepts:
     *   - null / '' / [] → null
     *   - string         → returned verbatim
     *   - object / array / scalar → joined list of rendered entries
     */
    private static function renderHeader(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        if (is_string($raw)) {
            return $raw;
        }
        $entries = is_array($raw) ? $raw : [$raw];
        $parts = [];
        foreach ($entries as $entry) {
            if (is_object($entry)) {
                $name  = (string) ($entry->name ?? $entry->personal ?? '');
                $email = (string) ($entry->mail ?? $entry->address ?? $entry->email ?? '');
            } elseif (is_array($entry)) {
                $name  = (string) ($entry['name'] ?? $entry['personal'] ?? '');
                $email = (string) ($entry['mail'] ?? $entry['address'] ?? $entry['email'] ?? '');
            } else {
                $name  = '';
                $email = (string) $entry;
            }
            $parts[] = $name !== '' ? "{$name} <{$email}>" : $email;
        }
        return implode(', ', $parts);
    }

    /**
     * Decode a transfer-encoded body.
     *
     * Supports "quoted-printable", "base64", and the no-op "7bit"/"8bit"
     * encodings. Unknown encodings return the body unchanged.
     */
    public static function decodeTransferEncoding(string $body, string $encoding): string
    {
        $enc = strtolower(trim($encoding));
        return match ($enc) {
            'quoted-printable' => self::decodeQuotedPrintable($body),
            'base64'           => self::decodeBase64($body),
            '7bit', '8bit', 'binary' => $body,
            default            => $body,
        };
    }

    /**
     * Decode a quoted-printable encoded string.
     */
    public static function decodeQuotedPrintable(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/=\r?\n/', '', $s) ?? $s;
        $decoded = quoted_printable_decode($s);
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            $decoded = mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
        }
        return $decoded;
    }

    /**
     * Decode a base64 encoded string. Tolerant of whitespace; returns
     * an empty string when the input is not valid base64.
     */
    public static function decodeBase64(string $s): string
    {
        $cleaned = preg_replace('/\s+/', '', $s) ?? $s;
        if ($cleaned === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $cleaned) !== 1) {
            return '';
        }
        $decoded = base64_decode($cleaned, true);
        if ($decoded === false) {
            return '';
        }
        return mb_check_encoding($decoded, 'UTF-8')
            ? $decoded
            : mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
    }

    /**
     * Normalize a message body for downstream rendering.
     *
     * Parses multipart/* structures with a basic boundary walk to pull out
     * text and html parts by content-type. For simple top-level types the
     * body is returned directly in the matching slot.
     *
     * @return array{text: ?string, html: ?string}
     */
    public static function parseBody(string $body, string $contentType): array
    {
        $lower = strtolower($contentType);

        if (str_contains($lower, 'multipart/')) {
            return self::parseMultipartBody($body, $contentType);
        }
        $filled = $body !== '' ? $body : null;
        $isHtml = str_contains($lower, self::HTML_TYPE);
        $isText = str_contains($lower, self::PLAIN_TYPE);
        return [
            'text' => $isText ? $filled : null,
            'html' => $isHtml ? $filled : null,
        ];
    }

    /**
     * Multipart branch of parseBody. Extracts the boundary, splits the body
     * into parts, then collects the first non-attachment plain and html parts.
     *
     * @return array{text: ?string, html: ?string}
     */
    private static function parseMultipartBody(string $body, string $contentType): array
    {
        $boundary = self::extractBoundary($contentType);
        if ($boundary === null) {
            return ['text' => null, 'html' => null];
        }
        $text = null;
        $html = null;
        foreach (self::splitMultipart($body, $boundary) as $part) {
            $ctLower = strtolower($part['content-type']);
            $dispLower = strtolower($part['content-disposition']);
            $isHtml = str_contains($ctLower, self::HTML_TYPE);
            $isText = str_contains($ctLower, self::PLAIN_TYPE);
            $slotTaken = ($isHtml && $html !== null) || (!$isHtml && $isText && $text !== null);
            $isAttachment = str_contains($dispLower, 'attachment');
            if ($isAttachment || (!$isHtml && !$isText) || $slotTaken) {
                continue;
            }
            $decoded = self::decodeTransferEncoding($part['body'], $part['transfer-encoding']);
            if ($isHtml) {
                $html = $decoded;
            } else {
                $text = $decoded;
            }
        }
        return ['text' => $text, 'html' => $html];
    }

    /**
     * Choose the most readable text representation of a message body.
     * Prefers the plain text part; falls back to a tag-stripped html part.
     */
    public static function selectReadableBody(?string $text, ?string $html): string
    {
        if ($text !== null && trim($text) !== '') {
            return $text;
        }
        if ($html !== null && $html !== '') {
            return strip_tags($html);
        }
        return '';
    }

    /**
     * @deprecated Use {@see AttachmentExtractor::extract()} directly.
     *             Kept as a thin shim for callers that still use
     *             MessageParser::extractAttachments().
     *
     * @param list<array{headers?: array<string, string>, body?: string}> $parts
     * @return list<array{filename: ?string, content_type: string, size: int, content_id: ?string, disposition: string}>
     */
    public static function extractAttachments(array $parts): array
    {
        return AttachmentExtractor::extract($parts);
    }

    /**
     * Extract the boundary parameter from a Content-Type header.
     */
    private static function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary\s*=\s*"?([^";\s]+)"?/i', $contentType, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Split a multipart body into parts, each with their headers and body.
     *
     * @return list<array{content-type: ?string, content-disposition: ?string, transfer-encoding: ?string, body: string}>
     */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $parts = [];
        foreach (explode($delimiter, $body) as $section) {
            $parsed = self::parsePartSection($section);
            if ($parsed !== null) {
                $parts[] = $parsed;
            }
        }
        return $parts;
    }

    /**
     * Parse one section of a multipart body into the part tuple, or null
     * for the boundary preamble / malformed sections.
     *
     * @return ?array{content-type: ?string, content-disposition: ?string, transfer-encoding: ?string, body: string}
     */
    private static function parsePartSection(string $section): ?array
    {
        $preambles = ['', "--\r\n", "--\n", '--'];
        $section = ltrim($section, "\r\n");
        $split = preg_split("/\r?\n\r?\n/", $section, 2);
        $isJunk = in_array($section, $preambles, true)
            || $section === ''
            || $split === false
            || count($split) < 2;
        if ($isJunk) {
            return null;
        }
        [$headerBlock, $partBody] = $split;
        $headers = [];
        foreach (preg_split("/\r?\n/", $headerBlock) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return [
            'content-type'        => $headers['content-type'] ?? '',
            'content-disposition' => $headers['content-disposition'] ?? '',
            'transfer-encoding'   => $headers['content-transfer-encoding'] ?? '7bit',
            'body'                => $partBody,
        ];
    }
}
