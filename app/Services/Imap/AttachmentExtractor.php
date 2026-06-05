<?php

declare(strict_types=1);

namespace Spora\Services\Imap;

/**
 * Pure helper that converts a list of parsed MIME parts into the normalized
 * attachment metadata shape that the rest of the application consumes.
 *
 * The input shape comes from MessageParser::splitMultipart() and friends:
 *   - 'headers'  : array<string, string> — raw header values, keys may
 *                  arrive as `content-type` or `Content-Type` depending on
 *                  the upstream parser.
 *   - 'body'     : string
 *
 * The output shape:
 *   - filename    : best-effort filename, or null when neither
 *                   Content-Disposition's filename= nor Content-Type's
 *                   name= is present.
 *   - content_type: lowercased Content-Type, or '' when missing.
 *   - size        : body length in bytes.
 *   - content_id  : Content-Id with angle brackets stripped, or null.
 *   - disposition: always 'attachment' (parts without that disposition
 *                   are filtered upstream by is_attachment()).
 */
final class AttachmentExtractor
{
    /**
     * @param list<array{headers?: array<string, string>, body?: string}> $parts
     * @return list<array{filename: ?string, content_type: string, size: int, content_id: ?string, disposition: string}>
     */
    public static function extract(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            $headers = $part['headers'] ?? [];
            $ct      = self::lookupHeaderLower($headers, 'content-type');
            $disp    = self::lookupHeaderLower($headers, 'content-disposition');
            $isAttachment = str_contains($disp, 'attachment');
            $isText       = str_contains($ct, 'text/');
            if (!$isAttachment || $isText) {
                continue;
            }
            $body = (string) ($part['body'] ?? '');
            $out[] = [
                'filename'     => self::extractFilename($disp, $ct),
                'content_type' => $ct,
                'size'         => strlen($body),
                'content_id'   => self::findContentId($headers),
                'disposition'  => 'attachment',
            ];
        }
        return $out;
    }

    /**
     * Case-insensitive header lookup returning the lowercased value, or ''
     * if the key is missing under any casing.
     *
     * @param array<string, string> $headers
     */
    private static function lookupHeaderLower(array $headers, string $name): string
    {
        if (isset($headers[$name])) {
            return strtolower((string) $headers[$name]);
        }
        $lower = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $lower) {
                return strtolower((string) $v);
            }
        }
        return '';
    }

    /**
     * Pull the filename from a Content-Disposition or Content-Type header.
     * Disposition's `filename=` is authoritative; type's `name=` is fallback.
     *
     * @param string $disp Already-lowercased Content-Disposition.
     * @param string $ct   Already-lowercased Content-Type.
     */
    private static function extractFilename(string $disp, string $ct): ?string
    {
        if (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disp, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/name\s*=\s*"?([^";]+)"?/i', $ct, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Look up `content-id` case-insensitively, then strip the surrounding
     * angle brackets (e.g. `<img-42@example.com>` → `img-42@example.com`).
     *
     * @param array<string, string> $headers
     */
    private static function findContentId(array $headers): ?string
    {
        $raw = $headers['content-id'] ?? null;
        if ($raw === null) {
            foreach ($headers as $k => $v) {
                if (strtolower((string) $k) === 'content-id') {
                    $raw = (string) $v;
                    break;
                }
            }
        }
        return $raw !== null ? trim((string) $raw, '<>') : null;
    }
}
