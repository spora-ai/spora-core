<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Core\Paths;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\RequestLimits;
use Spora\Models\MediaAsset;
use Spora\Services\LocalAssetStore;

/**
 * The image block an archive asset renders into, and the byte caps that
 * bound it.
 *
 * One owner for the two paths that put an archive image in front of a
 * model — the operator's attachment and a tool result that points at an
 * asset — so the caps and the byte loading cannot drift between them.
 * The per-asset cap is the endpoint's, not Spora's: the provider measures
 * the base64 the request carries, so the file that fits is three quarters
 * of the limit the provider publishes.
 */
final class MediaImageBlocks
{
    /**
     * Hard cap on the inline image bytes the worker holds.
     *
     * A 4K photo can exceed 20 MiB after MIME decode; without a cap, a
     * single oversized attachment blows up the LLM context window and the
     * request payload. It is the looser of the two caps — the provider's
     * own ceiling ({@see self::maxImageBytesFor()}) is the one that
     * normally refuses.
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
     * The largest image the endpoint behind a driver accepts, in raw bytes.
     *
     * Two ceilings apply and the stricter one wins: the hard cap on what
     * the worker holds ({@see self::MAX_IMAGE_BYTES}), and the provider's
     * cap on the **encoded** image. The provider measures the base64 the
     * request carries, which spends four bytes on every three, so the raw
     * file that fits is three quarters of the number the provider states —
     * a file inside Spora's own cap can still be one the provider
     * answers with a 400, and it would be on every rebuilt history.
     */
    public static function maxImageBytesFor(?LLMDriverInterface $driver): int
    {
        return min(
            self::MAX_IMAGE_BYTES,
            RequestLimits::maxRawBytesFor(RequestLimits::maxEncodedImageBytesFor($driver)),
        );
    }

    /**
     * The budget the images of one request share.
     *
     * The request carries the system prompt, the tools and the
     * conversation text as well as the images, and only the images are
     * sized here — so three quarters of the provider's request ceiling is
     * the images' share, and the remaining quarter is the text's.
     */
    public static function requestBudgetFor(?LLMDriverInterface $driver): ImageRequestBudget
    {
        return new ImageRequestBudget(
            RequestLimits::maxImageBytesForRequest(RequestLimits::maxRequestBytesFor($driver)),
        );
    }

    /**
     * The image block and the raw bytes it spent.
     *
     * The byte count travels with the block so a caller that budgets a
     * row's images does not have to decode the base64 again; the encoded
     * count travels with it because that is the size the provider
     * measures.
     *
     * @param  int  $maxImageBytes  The ceiling on the bytes of one image.
     * @return array{block: array<string, mixed>, bytes: int, encodedBytes: int}|null
     */
    public static function renderAsset(
        MediaAsset $asset,
        string $basePath,
        int $maxImageBytes = self::MAX_IMAGE_BYTES,
    ): ?array {
        $bytes = self::loadBytes($asset, $basePath);
        if ($bytes === null || strlen($bytes) > $maxImageBytes) {
            return null;
        }

        $base64 = base64_encode($bytes);

        return [
            'block' => [
                'type'      => 'image',
                'mediaType' => (string) ($asset->mime_type ?? 'application/octet-stream'),
                'base64'    => $base64,
            ],
            'bytes'        => strlen($bytes),
            'encodedBytes' => strlen($base64),
        ];
    }

    /**
     * The image block an asset contributes to a row, and the bytes it spent.
     *
     * Null when the asset cannot be rendered or does not fit the row's
     * remaining budget — the per-asset cap, the row cap and the request's
     * share are all enforced here, so every path that puts an image in a
     * row asks this one method and they cannot drift.
     *
     * The recorded size decides whether the asset fits before it is read,
     * so an image that cannot fit is never loaded off disk; the loaded
     * bytes are measured again, because the recorded size can be stale.
     *
     * @param  int  $rowBytes       The image bytes the row has already spent.
     * @param  int  $maxRowBytes    The ceiling on the image bytes one row forwards.
     * @param  int  $maxImageBytes  The ceiling on the bytes of one image.
     * @param  ImageRequestBudget|null  $budget  What is left of the request.
     * @return array{block: array<string, mixed>, bytes: int, encodedBytes: int}|null
     */
    public static function renderForRow(
        MediaAsset $asset,
        string $basePath,
        int $rowBytes,
        int $maxRowBytes = self::MAX_ROW_BYTES,
        int $maxImageBytes = self::MAX_IMAGE_BYTES,
        ?ImageRequestBudget $budget = null,
    ): ?array {
        $size = (int) ($asset->byte_size ?? 0);
        $fits = $size > 0
            ? $rowBytes + $size <= $maxRowBytes && $size <= $maxImageBytes
            : $rowBytes < $maxRowBytes;

        if (!$fits) {
            return null;
        }

        $rendered = self::renderAsset($asset, $basePath, $maxImageBytes);

        // The row budget is spent in raw bytes — the worker's own copy of
        // the image — and the request budget in encoded bytes, because
        // that is what the provider measures, and a request over the limit
        // is refused as a whole.
        if ($rendered === null
            || $rowBytes + $rendered['bytes'] > $maxRowBytes
            || ($budget !== null && !$budget->accepts($rendered['encodedBytes']))
        ) {
            return null;
        }

        return $rendered;
    }

    /**
     * Whether the asset's bytes went missing.
     *
     * The row is there and the bytes are not: the file was swept by the
     * asset GC, or the storage failed. That is a removal the caller has
     * to hear about — {@see self::renderForRow()} answers null for it and
     * for the image that simply did not fit, and only this says which.
     *
     * The check probes for the bytes rather than reading them: the
     * render that just failed has read the file already, and a second
     * read of an image that was dropped for size costs a full pass over
     * its bytes to learn nothing.
     *
     * An `external` asset is not one of those: its bytes live on someone
     * else's server and were never on hand here, so nothing has gone
     * missing.
     */
    public static function bytesWentMissing(MediaAsset $asset, string $basePath): bool
    {
        if ($asset->storage_mode === 'data_url') {
            return !is_string($asset->payload);
        }

        if ($asset->storage_mode === 'local') {
            $path = self::payloadPath($asset, $basePath);

            return $path === null || !is_file($path) || !is_readable($path);
        }

        return false;
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

        $path = self::payloadPath($asset, $basePath);
        if ($path === null || !is_file($path)) {
            return null;
        }

        // A failed read is not an empty file: `file_get_contents()`
        // returns false, and casting that to a string would hand the
        // caller a zero-byte image that reads as a successful load.
        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Where a `local`-mode asset's bytes live, or null when it has none.
     *
     * The writer's rule, not a MIME-only guess: the archive stores a
     * local asset under the suffix its own `filename` produced (an
     * upload named `photo.jpeg` lands as `<token>.jpeg`, because the
     * filename wins over the MIME map's `jpg`), so the reader has to ask
     * the same question the writer did.
     */
    private static function payloadPath(MediaAsset $asset, string $basePath): ?string
    {
        if ($asset->storage_mode !== 'local') {
            return null;
        }

        if ($asset->asset_token === null || $asset->asset_token === '') {
            return null;
        }

        $paths = new Paths($basePath);

        return $paths->storage('assets') . '/' . $asset->asset_token
            . '.' . LocalAssetStore::storedExtension($asset->mime_type, $asset->filename);
    }
}
