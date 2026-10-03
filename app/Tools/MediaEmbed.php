<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\MediaAsset;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaType;

/**
 * Helpers for emitting consistent inline media in {@see ValueObjects\ToolResult::$content}.
 *
 * The chat UI's markdown sanitizer (`spora-frontend/src/composables/useMarkdown.ts`)
 * whitelists `<audio>`, `<video>`, `<source>`, and `<img>`, plus the
 * `data:` URI scheme on audio/video/source src attributes only. Use this
 * class so the emitted HTML stays in sync with that allow-list; do not
 * hand-roll `<audio src="...">` in plugin code. {@see fileCard()} adds
 * nothing to the allow-list either — it reuses the `div` / `a` / `span`
 * tags and the `class` / `href` attributes the sanitizer already passes
 * through — so it has to be revisited whenever that list changes.
 *
 * The class is final and stateless. It does NOT log, write to disk, or
 * talk to the network — that's the {@see AssetStore}'s job. These
 * helpers only format strings.
 */
final class MediaEmbed
{
    /**
     * Static-only utility class — prevents `new MediaEmbed()` instantiation
     * (which would be a misuse) and stops child classes from inheriting it.
     * Without this, every consumer would have to remember not to
     * instantiate it, and the static-only contract would be enforced by
     * convention alone.
     */
    private function __construct() {}

    /**
     * Markdown image syntax. `$alt` is HTML-escaped AND Markdown-metacharacter
     * escaped so an untrusted alt can't break out of `![alt]` and inject
     * additional Markdown/HTML (e.g. `alt = "x](javascript:alert(1))"`).
     * `$url` is only HTML-escaped (URLs legitimately contain `&`,
     * `?`, etc.); the chat sanitizer handles URL context separately.
     */
    public static function image(string $url, string $alt = ''): string
    {
        $safeAlt = htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Escape Markdown control chars: `\` (escape), `]` and `[` (alt text
        // delimiters). Order matters — backslash first.
        $mdEscAlt = strtr($safeAlt, ['\\' => '\\\\', ']' => '\\]', '[' => '\\[']);
        return "![{$mdEscAlt}]({$url})";
    }

    /**
     * Markdown link syntax (`[text](url)`) with the same escape pattern
     * as {@see image()}: `$text` is HTML-escaped AND Markdown-metacharacter
     * escaped so an untrusted label can't break out of the brackets; `$url`
     * is HTML-escaped so HTML entities in URLs are neutralised before the
     * chat sanitizer sees them.
     */
    public static function link(string $url, string $text): string
    {
        $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $mdEsc    = strtr($safeText, ['\\' => '\\\\', ']' => '\\]', '[' => '\\[']);
        $safeUrl  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return "[{$mdEsc}]({$safeUrl})";
    }

    /**
     * `<audio>` element for a pre-resolved URL. The caller is responsible
     * for routing the URL through {@see AssetStore::store()} first if the
     * payload is bytes, not a URL.
     */
    public static function audioFromUrl(string $url): string
    {
        return '<audio controls preload="metadata" src="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '"></audio>';
    }

    /**
     * `<video>` element for a pre-resolved URL. `$width` and `$height`
     * are optional; pass them when the upstream API reports them so the
     * browser doesn't have to reflow after metadata loads.
     */
    public static function videoFromUrl(string $url, ?int $width = null, ?int $height = null): string
    {
        $size = '';
        if ($width !== null && $height !== null) {
            $size = ' width="' . $width . '" height="' . $height . '"';
        }
        return '<video controls preload="metadata" playsinline' . $size
            . ' src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></video>';
    }

    /**
     * Download card for an asset the chat UI cannot render inline — the
     * document bucket (`text/*`, `application/*`). The whole card is a
     * single `<a>`, which is already the correct semantics for a
     * download and needs no ARIA.
     *
     * Emitted on ONE line on purpose: the LLM reads
     * {@see ValueObjects\ToolResult::$content} too, and it should see a
     * filename and an href rather than a layout tree.
     *
     * There is deliberately no icon element in the markup. `aria-hidden`
     * is not in the sanitizer's `ALLOWED_ATTR`, so it would be silently
     * stripped and the glyph announced to screen readers; the glyph is a
     * CSS `::before` pseudo-element in spora-frontend instead, which
     * leaves nothing for the allow-list to strip. `download` is left off
     * for the same reason — `AssetController` forces
     * `Content-Disposition: attachment` from the stored filename anyway.
     *
     * `$byteSize` and `$mimeType` are both optional; the meta span is
     * omitted entirely when neither is known rather than emitted empty.
     */
    public static function fileCard(
        string $url,
        string $filename,
        ?int $byteSize = null,
        ?string $mimeType = null,
    ): string {
        // CR/LF are stripped before interpolation. The same stored filename
        // reaches the `Content-Disposition` header that
        // `AssetController::applyContentDisposition()` builds, so a value
        // carrying a raw newline is worth neutralising on the way past — but
        // note this is a render path and closes nothing there. That header
        // strips only quotes and backslashes, and closing it properly is a
        // separate change to `applyContentDisposition()`.
        $safeUrl = htmlspecialchars(self::stripLineBreaks($url), ENT_QUOTES, 'UTF-8');
        $name = basename(self::stripLineBreaks($filename));
        $safeName = htmlspecialchars($name === '' ? 'download' : $name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $meta = [];
        if ($byteSize !== null) {
            $meta[] = self::formatBytes($byteSize);
        }
        if ($mimeType !== null && trim($mimeType) !== '') {
            $meta[] = htmlspecialchars(trim($mimeType), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $card = '<div class="spora-file-card"><a class="spora-file-card__link" href="' . $safeUrl . '">'
            . '<span class="spora-file-card__name">' . $safeName . '</span>';
        if ($meta !== []) {
            $card .= '<span class="spora-file-card__meta">' . implode(' · ', $meta) . '</span>';
        }

        return $card . '</a></div>';
    }

    /**
     * One-call shortcut for the common plugin case: bytes in, embed out.
     * The bytes go through {@see AssetStore::store()}, which may produce
     * either a `data:` URL or a `/api/v1/assets/...` URL depending on
     * the operator's mode setting.
     */
    public static function audioFromBytes(string $bytes, AssetStore $store, string $filename = 'audio.mp3'): string
    {
        $ref = $store->store($bytes, mime: 'audio/mpeg', filename: $filename);
        return self::audioFromUrl($ref->url);
    }

    /**
     * Same as {@see audioFromBytes()} but for video payloads. Defaults to
     * `video/mp4` and `video.mp4`; override when the upstream returns a
     * different container (e.g. `video/webm` + `.webm`).
     */
    public static function videoFromBytes(string $bytes, AssetStore $store, string $filename = 'video.mp4'): string
    {
        $ref = $store->store($bytes, mime: 'video/mp4', filename: $filename);
        return self::videoFromUrl($ref->url);
    }

    /**
     * The media-type → embed dispatch, in one place so every operation that
     * surfaces an asset renders it identically.
     *
     * Lives here rather than on a tool because the taxonomy has more than one
     * entry point: `get_media` / `get_embed_code` describe an asset that
     * already exists, while `create_media` and `create_derivative` announce
     * one they just minted. A copy of this match per call site is how a PDF
     * ends up a card on one operation and a bare link on the next.
     *
     * `MediaType::Unknown` deliberately stays a plain link. `MediaType::fromMime()`
     * buckets every `application/*` and `text/*` — `application/octet-stream`
     * included — into `Document`, so `Unknown` means the `media_type` column
     * itself was null or unrecognised. Nothing about such a row is reliably a
     * download, so a bare link states less than a card would imply.
     */
    public static function forAsset(
        MediaAsset $asset,
        MediaType $mediaType,
        string $assetUrl,
        string $altText,
    ): string {
        return match ($mediaType) {
            MediaType::Image    => self::image($assetUrl, $altText),
            MediaType::Audio    => self::audioFromUrl($assetUrl),
            MediaType::Video    => self::videoFromUrl(
                $assetUrl,
                $asset->width !== null ? (int) $asset->width : null,
                $asset->height !== null ? (int) $asset->height : null,
            ),
            MediaType::Document => self::fileCard(
                $assetUrl,
                (string) ($asset->filename ?? ''),
                $asset->byte_size !== null ? (int) $asset->byte_size : null,
                $asset->mime_type !== null ? (string) $asset->mime_type : null,
            ),
            default             => self::link($assetUrl, $altText),
        };
    }

    private static function stripLineBreaks(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    /** Binary units the card's meta span renders; 1024-based like the rest of the archive's size reporting. */
    private static function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => $bytes . ' B',
            $bytes < 1024 * 1024 => sprintf('%.1f KB', $bytes / 1024),
            $bytes < 1024 * 1024 * 1024 => sprintf('%.1f MB', $bytes / (1024 * 1024)),
            default => sprintf('%.1f GB', $bytes / (1024 * 1024 * 1024)),
        };
    }
}
