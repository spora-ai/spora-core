<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use finfo;
use Throwable;

/**
 * Two-axis MIME type detection.
 *
 * `sniffFromBytes()` runs `finfo_buffer` over a small prefix and cross-checks
 * against a hand-rolled magic-byte table for the formats `finfo` is known to
 * mis-classify (most notably WebP and MP4 where the same ftyp box can match
 * several container families).
 *
 * `sniffFromExtension()` is the cheap path for the URL branch — when we have
 * only a URL before the body fetch, we look at the path's extension first so
 * we can short-circuit sniff and decide whether a HEAD request is worth the
 * round-trip. It is deliberately conservative: unrecognised extensions
 * return `application/octet-stream` rather than guessing.
 *
 * Both returners are pure; the class is stateless and safe to reuse as a
 * long-lived service.
 */
final class MimeSniffer
{
    /**
     * Magic-byte signatures indexed by their canonical MIME type. Each
     * signature is `prefix_offset` => `byte_string`. The check requires the
     * prefix to start at exactly that offset in the input — most formats
     * store the magic at offset 0, but a few (e.g. MP4) put the ftyp box
     * at a known fixed offset.
     *
     * @var array<string, list<array{offset: int, bytes: string}>>
     */
    private const MAGIC_SIGNATURES = [
        'image/png'  => [['offset' => 0, 'bytes' => "\x89PNG\r\n\x1a\n"]],
        'image/jpeg' => [['offset' => 0, 'bytes' => "\xFF\xD8\xFF"]],
        'image/gif'  => [['offset' => 0, 'bytes' => 'GIF87a'], ['offset' => 0, 'bytes' => 'GIF89a']],
        'image/webp' => [
            ['offset' => 0, 'bytes' => 'RIFF'],
            // WebP: 'RIFF????WEBP' — must contain WEBP at offset 8.
            ['offset' => 8, 'bytes' => 'WEBP'],
        ],
        'audio/mpeg' => [['offset' => 0, 'bytes' => "\xFF\xFB"], ['offset' => 0, 'bytes' => "\xFF\xF3"], ['offset' => 0, 'bytes' => 'ID3']],
        'audio/wav'  => [
            ['offset' => 0, 'bytes' => 'RIFF'],
            ['offset' => 8, 'bytes' => 'WAVE'],
        ],
        'audio/ogg'  => [['offset' => 0, 'bytes' => 'OggS']],
        'audio/flac' => [['offset' => 0, 'bytes' => 'fLaC']],
        'video/mp4'  => [
            ['offset' => 4, 'bytes' => 'ftyp'],
        ],
        'video/webm' => [
            ['offset' => 0, 'bytes' => "\x1A\x45\xDF\xA3"],
        ],
        'video/quicktime' => [
            ['offset' => 4, 'bytes' => 'ftyp'],
            // qt  marker at offset 8 — checked separately below.
        ],
        'application/pdf' => [['offset' => 0, 'bytes' => '%PDF-']],
    ];

    /**
     * Reverse lookup: extension (lowercase, no dot) → MIME. Conservative —
     * only formats where the extension is unambiguous. PNG is "image/png",
     * but `bin` could be anything, so it's deliberately omitted.
     *
     * @var array<string, string>
     */
    private const EXT_TO_MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'flac' => 'audio/flac',
        'm4a'  => 'audio/mp4',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mov'  => 'video/quicktime',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
    ];

    /**
     * Sniff MIME from raw bytes. `finfo` is the primary detector; the
     * magic-byte table is consulted as a tie-breaker for the formats where
     * `finfo` is unreliable (WebP and MP4 brand variants). Always returns
     * a non-empty string — falls back to `application/octet-stream` when
     * nothing matches.
     */
    public function sniffFromBytes(string $bytes): string
    {
        if ($bytes === '') {
            return 'application/octet-stream';
        }

        // finfo requires at least a few bytes — pass a 4 KiB prefix.
        $prefix = substr($bytes, 0, 4096);

        // Run the magic table first — for the formats we know about it's
        // more reliable than `finfo` (which labels MP3 sync frames as
        // `text/plain`, WebP as `application/octet-stream`, etc.). Only
        // when the magic table misses do we fall back to finfo.
        $magicHit = $this->matchMagicTable($prefix);
        if ($magicHit !== null) {
            return $magicHit;
        }

        $detected = $this->finfoBuffer($prefix);
        if ($detected !== null) {
            $refined = $this->refineWithMagicTable($prefix, $detected);
            return $refined ?? $detected;
        }

        return 'application/octet-stream';
    }

    /**
     * Sniff MIME from a filename or URL by extension. Conservative — returns
     * `application/octet-stream` for unknown extensions so callers don't
     * accidentally trust a guess.
     */
    public function sniffFromExtension(?string $filenameOrUrl): string
    {
        if ($filenameOrUrl === null || $filenameOrUrl === '') {
            return 'application/octet-stream';
        }

        // Strip query string and any leading path so the extension is the
        // last component.
        $path = parse_url($filenameOrUrl, PHP_URL_PATH) ?: $filenameOrUrl;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return 'application/octet-stream';
        }

        return self::EXT_TO_MIME[$ext] ?? 'application/octet-stream';
    }

    private function finfoBuffer(string $prefix): ?string
    {
        if (!function_exists('finfo_buffer') || !class_exists(finfo::class)) {
            return null;
        }
        try {
            /** @var resource|null $ctx */
            static $ctx = null;
            if ($ctx === null) {
                $ctx = @finfo_open(\FILEINFO_MIME_TYPE);
            }
            if ($ctx === false) {
                return null;
            }
            $mime = @finfo_buffer($ctx, $prefix);
            return is_string($mime) && $mime !== '' ? $mime : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Some formats (WebP, MP4 brand variants) need a refined check on top
     * of `finfo` because `finfo` reports the parent container type
     * (`application/octet-stream` for WebP, `video/mp4` for HEVC inside
     * ISOBMFF) and gets confused by sub-brands.
     *
     * @param string $prefix The same bytes passed to `finfo`
     * @param string $finfoDetected The MIME finfo reported
     */
    private function refineWithMagicTable(string $prefix, string $finfoDetected): ?string
    {
        // WebP: finfo typically reports application/octet-stream — check
        // the WEBP marker at offset 8 and prefer image/webp.
        if (strlen($prefix) >= 12 && substr($prefix, 0, 4) === 'RIFF' && substr($prefix, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        // MP4 brand discrimination: finfo says video/mp4 for any ISOBMFF;
        // we want quicktime for the qt  brand.
        if (strlen($prefix) >= 12 && substr($prefix, 4, 4) === 'ftyp') {
            $brand = substr($prefix, 8, 4);
            if ($brand === 'qt  ') {
                return 'video/quicktime';
            }
            return 'video/mp4';
        }

        // For everything else, trust finfo.
        unset($finfoDetected);
        return null;
    }

    private function matchMagicTable(string $prefix): ?string
    {
        foreach (self::MAGIC_SIGNATURES as $mime => $signatures) {
            foreach ($signatures as $sig) {
                if (strlen($prefix) < $sig['offset'] + strlen($sig['bytes'])) {
                    continue;
                }
                if (substr($prefix, $sig['offset'], strlen($sig['bytes'])) === $sig['bytes']) {
                    return $mime;
                }
            }
        }
        return null;
    }
}
