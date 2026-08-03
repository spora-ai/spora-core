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
 * The 16 MiB default matches MySQL/MariaDB's MEDIUMBLOB ceiling — see
 * migration 0064 which widens `media_assets.payload` from BLOB (64 KiB)
 * to MEDIUMBLOB. Larger payloads must be routed to {@see LocalAssetStore}.
 */
final class DatabaseAssetStore implements AssetStore
{
    public const MAX_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly int $maxBytes = self::MAX_BYTES,
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

        // URL is a stable `data:` marker rather than an empty string.
        // MediaArchiveService overrides it with the opaque
        // /api/v1/assets/<uuid> form on persist; until that point,
        // callers that pass `$ref->url` straight into {@see MediaEmbed}
        // must still produce syntactically valid HTML. An empty `<audio
        // src="">` tag is a broken UI element. The random suffix keeps
        // idempotency keys distinct per call (so two concurrent ingests
        // of the same payload don't collide on dedup lookups) without
        // ever being served — it never resolves.
        $marker = 'data:application/octet-stream;base64,' . bin2hex(random_bytes(8));
        return new AssetReference(url: $marker, mode: 'data_url');
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
