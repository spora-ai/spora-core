<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\MediaAsset;

/**
 * DB-backed {@see AssetStore}. The `store()` call validates size only;
 * the actual BLOB write happens later via
 * {@see MediaArchive\MediaArchiveService::writePayloadToAsset()}
 * after the row UUID is allocated, so the opaque URL is in place before
 * the payload lands and a concurrent reader never sees a half-loaded row.
 *
 * 50 MiB default is the practical DB ceiling — MySQL/MariaDB's stock BLOB
 * is 64 KiB; payloads above the threshold are routed to
 * {@see LocalAssetStore} by {@see AutoAssetStore}.
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

        // URL is advisory — MediaArchiveService overrides it with the
        // opaque /api/v1/assets/<uuid> form on persist.
        return new AssetReference(url: '', mode: 'data_url');
    }

    /**
     * @throws AssetStorageException when the row has no payload (legacy
     *         external rows or pre-refactor rows whose BLOB write failed).
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
