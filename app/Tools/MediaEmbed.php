<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\AssetStore;

/**
 * Helpers for emitting consistent inline media in {@see ValueObjects\ToolResult::$content}.
 *
 * The chat UI's markdown sanitizer (`spora-frontend/src/composables/useMarkdown.ts`)
 * whitelists `<audio>`, `<video>`, `<source>`, and `<img>`, plus the
 * `data:` URI scheme on audio/video/src src attributes only. Use this
 * class so the emitted HTML stays in sync with that allow-list; do not
 * hand-roll `<audio src="...">` in plugin code.
 *
 * The class is final and stateless. It does NOT log, write to disk, or
 * talk to the network — that's the {@see AssetStore}'s job. These
 * helpers only format strings.
 */
final class MediaEmbed
{
    private function __construct() {}

    /**
     * Markdown image syntax. `$alt` is HTML-escaped; `$url` is not (URLs
     * can legitimately contain `&` in query strings, and the sanitizer
     * handles URL context separately).
     */
    public static function image(string $url, string $alt = ''): string
    {
        $safeAlt = htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return "![{$safeAlt}]({$url})";
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
}
