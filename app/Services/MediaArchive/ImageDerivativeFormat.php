<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * Single source of truth for the image-derivative preset catalogue.
 *
 * Both {@see ImageDerivativeProducer} and the options-endpoint label
 * synthesis walk this list, so a typo or rename in one place can't
 * drift the producer's `supportedDerivativeFormats()` from what the
 * UI surfaces.
 *
 * Each preset encodes one of two operations:
 *   - `kind === 'resize'` — `scaleDown(longEdge)`, preserving aspect.
 *     The preset never upscales; passing a smaller source returns the
 *     original dimensions.
 *   - `kind === 'convert'` — re-encode to `targetMime` at `quality`,
 *     no resize. Used for storage savings (PNG → WebP) and to force a
 *     browser-friendly output regardless of what the operator uploaded.
 *
 * `quality` is omitted for the PNG preset (PNG is lossless; the
 * encoder ignores the parameter). The field stays on the row so the
 * test fixture doesn't special-case PNG.
 */
final class ImageDerivativeFormat
{
    public const KIND_RESIZE = 'resize';
    public const KIND_CONVERT = 'convert';

    /**
     * Catalogue of every preset the producer advertises. Order matters
     * only for the UI dropdown — the producer doesn't depend on it.
     *
     * @var list<array{format: string, label: string, kind: string, longEdge: ?int, targetMime: string, quality: ?int}>
     */
    public const FORMAT_PRESETS = [
        [
            'format'    => 'thumbnail-256',
            'label'     => 'Thumbnail (256px)',
            'kind'      => self::KIND_RESIZE,
            'longEdge'  => 256,
            'targetMime' => 'image/webp',
            'quality'   => 80,
        ],
        [
            'format'    => 'medium-1024',
            'label'     => 'Medium (1024px)',
            'kind'      => self::KIND_RESIZE,
            'longEdge'  => 1024,
            'targetMime' => 'image/webp',
            'quality'   => 80,
        ],
        [
            'format'    => 'format-png',
            'label'     => 'Convert to PNG',
            'kind'      => self::KIND_CONVERT,
            'longEdge'  => null,
            'targetMime' => 'image/png',
            'quality'   => null,
        ],
        [
            'format'    => 'format-jpeg',
            'label'     => 'Convert to JPEG',
            'kind'      => self::KIND_CONVERT,
            'longEdge'  => null,
            'targetMime' => 'image/jpeg',
            'quality'   => 85,
        ],
        [
            'format'    => 'format-webp',
            'label'     => 'Convert to WebP',
            'kind'      => self::KIND_CONVERT,
            'longEdge'  => null,
            'targetMime' => 'image/webp',
            'quality'   => 80,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function formatKeys(): array
    {
        return array_map(static fn(array $row): string => $row['format'], self::FORMAT_PRESETS);
    }

    /**
     * Look up the preset row for `$format`. Returns null when the key
     * doesn't appear in the catalogue — callers fall back to a
     * slug-based label so producers outside this class still render
     * something sensible.
     *
     * @return array{format: string, label: string, kind: string, longEdge: ?int, targetMime: string, quality: ?int}|null
     */
    public static function for(string $format): ?array
    {
        foreach (self::FORMAT_PRESETS as $row) {
            if ($row['format'] === $format) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Resolve a human label for `$format`. Falls back to an
     * upper-cased slug when no preset matches — future producers that
     * ship without a corresponding row in {@see FORMAT_PRESETS} still
     * get a presentable label instead of `null`/empty.
     */
    public static function labelFor(string $format): string
    {
        $preset = self::for($format);
        return $preset !== null ? $preset['label'] : strtoupper($format);
    }

    /**
     * Short, chip-friendly label for the VersionsStrip dropdown.
     *
     * The full {@see labelFor()} strings ("Thumbnail (256px)") are
     * perfect for the "Convert to…" select but too wide for a pill chip
     * next to the source asset. This collapses them to a tag-style
     * identifier that fits: "Thumb 256", "PNG", "WebP", "JPEG". Falls
     * back to the upper-case format slug for unknown keys so producers
     * outside the catalogue still render something readable.
     */
    public static function chipLabelFor(string $format): string
    {
        return match ($format) {
            'thumbnail-256' => 'Thumb 256',
            'medium-1024'   => 'Medium 1024',
            'format-png'    => 'PNG',
            'format-jpeg'   => 'JPEG',
            'format-webp'   => 'WebP',
            default         => strtoupper($format),
        };
    }
}
