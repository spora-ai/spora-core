<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * Coarse media-type discriminator surfaced by {@see MediaArchiveService}.
 *
 * Plugins can pass an explicit value; when omitted, the service derives one
 * from the sniffed MIME type. `Unknown` is the default for everything that
 * doesn't match a recognised prefix — kept as a first-class value so the
 * list filter has a stable behaviour for exotic uploads rather than
 * silently dropping them.
 */
enum MediaType: string
{
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Document = 'document';
    case Unknown = 'unknown';

    public static function fromMime(?string $mime): self
    {
        if ($mime === null || $mime === '') {
            return self::Unknown;
        }
        $primary = strtolower(strstr($mime, '/', before_needle: true) ?: $mime);
        return match ($primary) {
            'image' => self::Image,
            'audio' => self::Audio,
            'video' => self::Video,
            'application', 'text' => self::Document,
            default => self::Unknown,
        };
    }
}
