<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Core\Paths;
use Spora\Models\MediaAsset;

/**
 * The image block an archive asset renders into, and the byte caps that
 * bound it.
 *
 * One owner for the two paths that put an archive image in front of a
 * model — the operator's attachment and a tool result that points at an
 * asset — so the caps and the byte loading cannot drift between them.
 */
final class MediaImageBlocks
{
    /**
     * Hard cap on inline image bytes. A 4K photo can exceed 20 MiB
     * after MIME decode; without a cap, a single oversized attachment
     * blows up the LLM context window and the request payload.
     */
    public const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    /**
     * The ceiling on the image bytes one row forwards.
     *
     * The per-asset cap bounds one image; this bounds the row. An image
     * block is a base64 copy of the asset, so a row of images costs the
     * worker its own bytes before the provider request is even built.
     */
    public const MAX_ROW_BYTES = 32 * 1024 * 1024;

    /**
     * The image block for the asset, or null when it cannot be rendered.
     *
     * Null covers the assets whose bytes are not on hand — an external
     * asset, a local file that is gone — and the ones over
     * {@see MAX_IMAGE_BYTES}.
     *
     * @return array<string, mixed>|null
     */
    public static function forAsset(MediaAsset $asset, string $basePath): ?array
    {
        $rendered = self::renderAsset($asset, $basePath);

        return $rendered === null ? null : $rendered['block'];
    }

    /**
     * The image block and the raw bytes it spent.
     *
     * The byte count travels with the block so a caller that budgets a
     * row's images does not have to decode the base64 again.
     *
     * @return array{block: array<string, mixed>, bytes: int}|null
     */
    public static function renderAsset(MediaAsset $asset, string $basePath): ?array
    {
        $bytes = self::loadBytes($asset, $basePath);
        if ($bytes === null || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return [
            'block' => [
                'type'      => 'image',
                'mediaType' => (string) ($asset->mime_type ?? 'application/octet-stream'),
                'base64'    => base64_encode($bytes),
            ],
            'bytes' => strlen($bytes),
        ];
    }

    /**
     * The asset's bytes, or null when they are not on hand.
     *
     * Only the two storage modes that carry the bytes are readable: a
     * `data_url` row holds them inline, a `local` row holds them on disk.
     * An `external` asset lives on someone else's server and is not
     * fetched here.
     */
    public static function loadBytes(MediaAsset $asset, string $basePath): ?string
    {
        if ($asset->storage_mode === 'data_url') {
            return is_string($asset->payload) ? $asset->payload : null;
        }
        if ($asset->storage_mode === 'local' && $asset->asset_token !== null && $asset->asset_token !== '') {
            $paths = new Paths($basePath);
            $path = $paths->storage('assets') . '/' . $asset->asset_token;
            $ext  = MediaArchiveService::extensionForMime($asset->mime_type);
            if ($ext !== null) {
                $path .= '.' . $ext;
            }
            return is_file($path) ? (string) file_get_contents($path) : null;
        }
        return null;
    }
}
