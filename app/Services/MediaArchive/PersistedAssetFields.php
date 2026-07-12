<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * Mutable bag of fields {@see MediaArchiveService::persist()} writes onto
 * a {@see MediaAsset} row. Pulled out of the service signature so the
 * method takes the shape of the model rather than ten positional
 * parameters — callers populate the relevant slots, `persist()` is the
 * one place that knows how they map onto the row.
 */
final class PersistedAssetFields
{
    public function __construct(
        public string $assetUrl,
        public ?string $sourceUrl,
        public string $storageMode,
        public string $sniffedMime,
        public MediaType $mediaType,
        public ?int $byteSize,
        public ?int $width,
        public ?int $height,
        public ?float $durationSeconds,
        public ?string $token = null,
    ) {}
}
