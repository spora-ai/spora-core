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

            $name = null;
            $email = null;

            if ($raw[$i] === '"') {
                // Quoted display name.
                $i++;
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

                // Skip whitespace and the following "<email>" part.
                while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === "\t")) {
                    $i++;
                }
                if ($i < $len && $raw[$i] === '<') {
                    $i++;
                    $emailBuf = '';
                    while ($i < $len && $raw[$i] !== '>') {
                        $emailBuf .= $raw[$i];
                        $i++;
                    }
                    if ($i < $len) {
                        $i++;
                    }
                    $email = $emailBuf;
                }
            } elseif ($raw[$i] === '<') {
                // <email> only.
                $i++;
                $emailBuf = '';
                while ($i < $len && $raw[$i] !== '>') {
                    $emailBuf .= $raw[$i];
                    $i++;
                }
                if ($i < $len) {
                    $i++;
                }
                $email = $emailBuf;
            } else {
                // Unquoted: either "Name <email>" or "email".
                $buf = '';
                while ($i < $len && $raw[$i] !== ',' && $raw[$i] !== '<') {
                    $buf .= $raw[$i];
                    $i++;
                }
                $buf = trim($buf);
                if ($i < $len && $raw[$i] === '<') {
                    $name = $buf !== '' ? $buf : null;
                    $i++;
                    $emailBuf = '';
                    while ($i < $len && $raw[$i] !== '>') {
                        $emailBuf .= $raw[$i];
                        $i++;
                    }
                    if ($i < $len) {
                        $i++;
                    }
                    $email = $emailBuf;
                } else {
                    $email = $buf !== '' ? $buf : null;
                }
            }

            if ($email !== null || $name !== null) {
                $result[] = ['name' => $name, 'email' => (string) $email];
            }
        }

        return $result;
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
        $get = static function (array $headers, string $key): mixed {
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
        };

        $render = static function (mixed $raw): ?string {
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
                    $email = (string) $entry;
                    $name = '';
                }
                $parts[] = $name !== '' ? "{$name} <{$email}>" : $email;
            }
            return implode(', ', $parts);
        };

        return [
            'from'    => $render($get($headers, 'from')),
            'to'      => $render($get($headers, 'to')),
            'subject' => ($v = $get($headers, 'subject')) !== null ? (string) $v : null,
            'date'    => ($v = $get($headers, 'date'))    !== null ? (string) $v : null,
            'cc'      => $render($get($headers, 'cc')),
            'bcc'     => $render($get($headers, 'bcc')),
        ];
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
        if ($cleaned === '') {
            return '';
        }
        // Strict mode: validate the entire string is base64 before decoding.
        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $cleaned) !== 1) {
            return '';
        }
        $decoded = base64_decode($cleaned, true);
        if ($decoded === false) {
            return '';
        }
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            $decoded = mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
        }
        return $decoded;
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

        // Multipart: walk the boundary.
        if (str_contains($lower, 'multipart/')) {
            $boundary = self::extractBoundary($contentType);
            if ($boundary === null) {
                return ['text' => null, 'html' => null];
            }
            $parts = self::splitMultipart($body, $boundary);
            $text = null;
            $html = null;
            foreach ($parts as $part) {
                $partCt = strtolower($part['content-type']);
                $disp   = strtolower($part['content-disposition'] ?? '');
                if (str_contains($disp, 'attachment')) {
                    continue;
                }
                $decoded = self::decodeTransferEncoding($part['body'], $part['transfer-encoding'] ?? '7bit');
                if (str_contains($partCt, 'text/html') && $html === null) {
                    $html = $decoded;
                } elseif (str_contains($partCt, 'text/plain') && $text === null) {
                    $text = $decoded;
                }
            }
            return ['text' => $text, 'html' => $html];
        }

        if (str_contains($lower, 'text/html')) {
            return ['text' => null, 'html' => $body !== '' ? $body : null];
        }
        if (str_contains($lower, 'text/plain')) {
            return ['text' => $body !== '' ? $body : null, 'html' => null];
        }

        return ['text' => null, 'html' => null];
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
     * Extract attachment metadata from a list of parsed MIME parts.
     *
     * Each part is expected to have:
     *   - 'headers' : array<string, string> containing content-type and
     *                 content-disposition
     *   - 'body'    : string
     *
     * @param list<array{headers?: array<string, string>, body?: string}> $parts
     * @return list<array{filename: ?string, content_type: string, size: int, content_id: ?string, disposition: string}>
     */
    public static function extractAttachments(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            $headers = $part['headers'] ?? [];
            $ct      = strtolower((string) ($headers['content-type'] ?? $headers['Content-Type'] ?? ''));
            $disp    = strtolower((string) ($headers['content-disposition'] ?? $headers['Content-Disposition'] ?? ''));

            if (!str_contains($disp, 'attachment')) {
                continue;
            }
            // Skip text/* parts even if they have name= or are flagged.
            if (str_contains($ct, 'text/')) {
                continue;
            }

            $filename = null;
            if (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disp, $m)) {
                $filename = $m[1];
            } elseif (preg_match('/name\s*=\s*"?([^";]+)"?/i', $ct, $m)) {
                $filename = $m[1];
            }

            $body = (string) ($part['body'] ?? '');
            $contentId = null;
            if (isset($headers['content-id'])) {
                $contentId = trim((string) $headers['content-id'], '<>');
            } elseif (isset($headers['Content-ID'])) {
                $contentId = trim((string) $headers['Content-ID'], '<>');
            }

            $out[] = [
                'filename'     => $filename,
                'content_type' => $ct,
                'size'         => strlen($body),
                'content_id'   => $contentId,
                'disposition'  => 'attachment',
            ];
        }
        return $out;
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
        $sections = explode($delimiter, $body);
        $parts = [];
        foreach ($sections as $section) {
            if ($section === '' || $section === "--\r\n" || $section === "--\n" || $section === '--') {
                continue;
            }
            // Strip leading CRLF after boundary marker.
            $section = ltrim($section, "\r\n");
            if ($section === '') {
                continue;
            }
            $split = preg_split("/\r?\n\r?\n/", $section, 2);
            if ($split === false || count($split) < 2) {
                continue;
            }
            [$headerBlock, $partBody] = $split;
            $headers = [];
            foreach (preg_split("/\r?\n/", $headerBlock) ?: [] as $headerLine) {
                if (str_contains($headerLine, ':')) {
                    [$k, $v] = array_pad(explode(':', $headerLine, 2), 2, '');
                    $headers[strtolower(trim($k))] = trim($v);
                }
            }
            $parts[] = [
                'content-type'        => $headers['content-type'] ?? '',
                'content-disposition' => $headers['content-disposition'] ?? '',
                'transfer-encoding'   => $headers['content-transfer-encoding'] ?? '7bit',
                'body'                => $partBody,
            ];
        }
        return $parts;
    }
}
