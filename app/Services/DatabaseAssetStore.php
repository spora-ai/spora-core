<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\MediaAsset;

/**
 * Database-backed {@see AssetStore}. The `store()` step is just a size
 * validation — the actual BLOB write is performed by
 * {@see MediaArchive\MediaArchiveService::writePayloadToAsset()}
 * once the row has its UUID, so the opaque `/api/v1/assets/<uuid>` URL
 * can be set on the row before the payload lands (a concurrent reader
 * never sees a half-loaded state).
 *
 * The 50 MiB default is a ceiling for the DB path; MySQL/MariaDB's stock
 * BLOB is 64 KiB, so {@see AutoAssetStore} routes larger payloads to
 * {@see LocalAssetStore} instead. SQLite has no intrinsic cap, so this
 * limit is a soft defense against runaway tool output, not a hard driver.
 */
final class DatabaseAssetStore implements AssetStore
{
    public function __construct(
        private readonly int $maxBytes = 50 * 1024 * 1024,
    ) {}

    public function store(string $bytes, ?string $mime = null, ?string $filename = null): AssetReference
    {
        $size = strlen($bytes);
        if ($size > $this->maxBytes) {
            throw new AssetTooLargeException(sprintf(
                'Asset of %d bytes exceeds DatabaseAssetStore ceiling of %d bytes. '
                    . 'Switch asset_store.mode to "local" or "auto" to handle larger payloads.',
                $size,
                $this->maxBytes,
            ));
        }

        // Advisory only — MediaArchiveService overwrites this with
        // `/api/v1/assets/<uuid>` so the URL is always opaque.
        return new AssetReference(url: '', mode: 'data_url');
    }

    /**
     * Read raw bytes back from a {@see MediaAsset} row. Throws if the
     * payload is missing (legacy external rows or pre-refactor rows whose
     * DB write failed mid-migration).
     *
     * @return array{bytes: string, mime: string, length: int, filename: ?string}
     */
    public function read(MediaAsset $asset): array
    {
        if ($asset->payload === null) {
            throw new AssetStorageException("MediaAsset {$asset->id} has no payload");
        }

        return [
            'bytes'    => $asset->payload,
            'mime'     => $asset->mime_type ?? 'application/octet-stream',
            'length'   => $asset->byte_size ?? strlen($asset->payload),
            'filename' => null,
        ];
    }
}
