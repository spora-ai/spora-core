<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use finfo;
use Psr\Log\LoggerInterface;
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
 * Both returners are pure and the only state is an optional logger, so it is
 * safe to reuse as a long-lived service. `sniffFromBytes()` is not quite a
 * function of its arguments alone, though: its final step consults
 * {@see MediaMimeRefinerDiscovery}, a process-global list, so a
 * registered plugin refiner can change the verdict for the same bytes.
 * That is the point of the seam — see the interface's docblock.
 */
final class MimeSniffer
{
    public function __construct(
        // Optional so `new MimeSniffer()` stays valid at the test call sites;
        // a null logger only costs the decline-and-continue path its
        // diagnostic, not its behaviour.
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Fallback MIME returned when nothing matched. Centralised so callers and
     * tests can refer to a single value rather than duplicating the literal.
     */
    public const OCTET_STREAM = 'application/octet-stream';

    /**
     * Typst source MIME. `finfo` reports `.typ` files as `text/plain`, so the
     * byte sniffer upgrades a `text/plain` hit to this value when the filename
     * extension confirms Typst.
     */
    private const string TYPST_MIME = 'text/x-typst';

    /**
     * Markdown MIME. `finfo` reports every Markdown file as `text/plain` —
     * it sniffs bytes, and Markdown is prose — so a `.md` upload is stored as
     * `text/plain` and the only surviving evidence of what it was is the
     * extension on the filename. That matters because
     * {@see MediaDerivativeService::findProducer()} matches a producer on the
     * parent's stored MIME *or* its extension, and because
     * {@see MediaArchiveService::extensionForMime()} has no `text/plain` →
     * `md` mapping to fall back on. Without this the whole Markdown-to-
     * derivative chain silently depends on the caller having spelled the
     * extension out.
     */
    private const string MARKDOWN_MIME = 'text/markdown';

    /**
     * Magic-byte signatures indexed by their canonical MIME type. The
     * structure is `MIME => list<list<signature>>`. Each MIME has a list
     * of alternative signature *groups*; every signature inside one group
     * must match for the group to count as a hit; any group matching
     * counts as a hit for the MIME. This is what distinguishes
     * `image/webp` (`RIFF` + `WEBP`, both required) from `audio/wav`
     * (`RIFF` + `WAVE`, both required): those compound formats are a
     * single signature group, so the magic table reports them only when
     * *both* offsets match. Conversely, `audio/mpeg` accepts any of three
     * sync variants (`0xFF 0xFB`, `0xFF 0xF3`, `ID3`) — each one is its
     * own single-signature group.
     *
     * Order of declaration does not matter — `matchMagicTable()` returns
     * the first MIME whose signature groups include a hit.
     *
     * @var array<string, list<list<array{offset: int, bytes: string}>>>
     */
    private const MAGIC_SIGNATURES = [
        'image/png'  => [
            [['offset' => 0, 'bytes' => "\x89PNG\r\n\x1a\n"]],
        ],
        'image/jpeg' => [
            [['offset' => 0, 'bytes' => "\xFF\xD8\xFF"]],
        ],
        'image/gif'  => [
            [['offset' => 0, 'bytes' => 'GIF87a']],
            [['offset' => 0, 'bytes' => 'GIF89a']],
        ],
        'image/webp' => [
            [
                ['offset' => 0, 'bytes' => 'RIFF'],
                ['offset' => 8, 'bytes' => 'WEBP'],
            ],
        ],
        'audio/mpeg' => [
            [['offset' => 0, 'bytes' => "\xFF\xFB"]],
            [['offset' => 0, 'bytes' => "\xFF\xF3"]],
            [['offset' => 0, 'bytes' => 'ID3']],
        ],
        'audio/wav'  => [
            [
                ['offset' => 0, 'bytes' => 'RIFF'],
                ['offset' => 8, 'bytes' => 'WAVE'],
            ],
        ],
        'audio/ogg'  => [
            [['offset' => 0, 'bytes' => 'OggS']],
        ],
        'audio/flac' => [
            [['offset' => 0, 'bytes' => 'fLaC']],
        ],
        'video/mp4'  => [
            [['offset' => 4, 'bytes' => 'ftyp']],
        ],
        'video/webm' => [
            [['offset' => 0, 'bytes' => "\x1A\x45\xDF\xA3"]],
        ],
        'application/pdf' => [
            [['offset' => 0, 'bytes' => '%PDF-']],
        ],
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
        'txt'      => 'text/plain',
        'typ'      => self::TYPST_MIME,
        'md'       => self::MARKDOWN_MIME,
        'markdown' => self::MARKDOWN_MIME,
        'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Sniff MIME from raw bytes. `finfo` is the primary detector; the
     * magic-byte table is consulted as a tie-breaker for the formats where
     * `finfo` is unreliable (WebP and MP4 brand variants). A filename can
     * refine generic `text/plain` detection for known text formats.
     * Always returns a non-empty string — falls back to
     * `application/octet-stream` when nothing matches.
     *
     * Receives the full byte string, not the 4 KiB prefix, so a
     * registered refiner can inspect as much of the payload as it needs.
     */
    public function sniffFromBytes(string $bytes, ?string $filename = null): string
    {
        if ($bytes === '') {
            return self::OCTET_STREAM;
        }

        // finfo requires at least a few bytes — pass a 4 KiB prefix.
        $prefix = substr($bytes, 0, 4096);

        $detected = $this->sniffPrefix($prefix);

        // A filename refines a generic `text/plain` verdict, but only ever
        // upward into a *more specific* text format — never sideways, and
        // never off `text/plain` at all. `.md` beats prose on both counts:
        // the bytes cannot tell Markdown from a plain-text log, and a
        // caller-supplied extension is the only signal available.
        if ($detected === 'text/plain' && $filename !== null) {
            $byExtension = $this->sniffFromExtension($filename);
            if ($byExtension === self::TYPST_MIME || $byExtension === self::MARKDOWN_MIME) {
                $detected = $byExtension;
            }
        }

        return $this->applyRegisteredRefiners($bytes, $filename, $detected);
    }

    /**
     * Last hop of the byte sniff: hand the verdict to every registered
     * {@see MediaMimeRefinerInterface} and take the first upgrade any of
     * them offers.
     *
     * Runs *after* the built-in Typst upgrade so a refiner always sees
     * the most specific MIME core can produce — a refiner that only
     * cared about `text/plain` would otherwise see a `text/x-typst`
     * input and a `text/plain` output as the same call.
     *
     * A refiner that throws is declined, not propagated. Registry entries
     * are plugin-supplied and therefore untrusted, and the exception
     * would otherwise travel `sniffFromBytes()` → `ingestFromBytes()` →
     * the upload controller and take down every media upload in the
     * process over one MIME verdict. Same posture as the derivative
     * producer: a plugin's throw degrades the extraction, never the
     * upload.
     */
    private function applyRegisteredRefiners(string $bytes, ?string $filename, string $sniffedMime): string
    {
        foreach (MediaMimeRefinerDiscovery::all() as $class) {
            // `new $class()` rather than a container lookup: the refiner
            // contract requires a no-arg constructor, and a container
            // round-trip here would make every MIME sniff depend on DI
            // being booted.
            try {
                $refined = (new $class())->refine($bytes, $filename, $sniffedMime);
            } catch (Throwable $e) {
                $this->logger?->warning('MimeSniffer: registered MIME refiner failed', [
                    'refiner' => $class,
                    'mime'    => $sniffedMime,
                    'error'   => $e->getMessage(),
                ]);

                continue;
            }

            if ($refined !== null) {
                return $refined;
            }
        }

        return $sniffedMime;
    }

    /**
     * Sniff MIME from a filename or URL by extension. Conservative — returns
     * `application/octet-stream` for unknown extensions so callers don't
     * accidentally trust a guess.
     */
    public function sniffFromExtension(?string $filenameOrUrl): string
    {
        if ($filenameOrUrl === null || $filenameOrUrl === '') {
            return self::OCTET_STREAM;
        }

        // Strip query string and any leading path so the extension is the
        // last component.
        $path = parse_url($filenameOrUrl, PHP_URL_PATH) ?: $filenameOrUrl;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return self::OCTET_STREAM;
        }

        return self::EXT_TO_MIME[$ext] ?? self::OCTET_STREAM;
    }

    /**
     * Core byte-level sniff. The magic table is consulted first — for the
     * formats we know about it's more reliable than `finfo` (which labels
     * MP3 sync frames as `text/plain`, WebP as `application/octet-stream`,
     * etc.). Only when the magic table misses do we fall back to finfo.
     */
    private function sniffPrefix(string $prefix): string
    {
        $magicHit = $this->matchMagicTable($prefix);
        if ($magicHit !== null) {
            return $magicHit;
        }

        $detected = $this->finfoBuffer($prefix);
        if ($detected === null) {
            return self::OCTET_STREAM;
        }

        return $this->refineWithMagicTable($prefix, $detected) ?? $detected;
    }

    private function finfoBuffer(string $prefix): ?string
    {
        if (!function_exists('finfo_buffer') || !class_exists(finfo::class)) {
            return null;
        }
        $ctx = $this->openFinfoContext();
        if ($ctx === null) {
            return null;
        }

        return $this->runFinfo($ctx, $prefix);
    }

    private function openFinfoContext(): mixed
    {
        /** @var resource|null $ctx */
        static $ctx = null;
        if ($ctx === null) {
            $ctx = @finfo_open(\FILEINFO_MIME_TYPE);
        }

        return $ctx === false ? null : $ctx;
    }

    private function runFinfo(mixed $ctx, string $prefix): ?string
    {
        try {
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
     */
    private function refineWithMagicTable(string $prefix, string $finfoDetected): ?string
    {
        $refined = $this->matchRefinedContainer($prefix);
        if ($refined !== null) {
            return $refined;
        }

        // For everything else, trust finfo.
        unset($finfoDetected);
        return null;
    }

    private function matchRefinedContainer(string $prefix): ?string
    {
        // WebP: finfo typically reports application/octet-stream — check
        // the WEBP marker at offset 8 and prefer image/webp.
        if ($this->isWebp($prefix)) {
            return 'image/webp';
        }

        // MP4 brand discrimination: finfo says video/mp4 for any ISOBMFF;
        // we want quicktime for the qt  brand.
        $mp4Brand = $this->readMp4Brand($prefix);
        if ($mp4Brand === null) {
            return null;
        }

        return $mp4Brand === 'qt  ' ? 'video/quicktime' : 'video/mp4';
    }

    private function isWebp(string $prefix): bool
    {
        return strlen($prefix) >= 12
            && substr($prefix, 0, 4) === 'RIFF'
            && substr($prefix, 8, 4) === 'WEBP';
    }

    private function readMp4Brand(string $prefix): ?string
    {
        if (strlen($prefix) < 12 || substr($prefix, 4, 4) !== 'ftyp') {
            return null;
        }

        return substr($prefix, 8, 4);
    }

    private function matchMagicTable(string $prefix): ?string
    {
        foreach (self::MAGIC_SIGNATURES as $mime => $groups) {
            foreach ($groups as $group) {
                if ($this->allSignaturesMatch($prefix, $group)) {
                    return $mime;
                }
            }
        }
        return null;
    }

    /**
     * @param list<array{offset: int, bytes: string}> $signatures
     */
    private function allSignaturesMatch(string $prefix, array $signatures): bool
    {
        foreach ($signatures as $sig) {
            $end = $sig['offset'] + strlen($sig['bytes']);
            if (strlen($prefix) < $end || substr($prefix, $sig['offset'], strlen($sig['bytes'])) !== $sig['bytes']) {
                return false;
            }
        }
        return true;
    }
}
