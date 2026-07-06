<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * Value object returned by {@see MetadataExtractor::extract()}.
 *
 * Holds whatever metadata the extractor could derive from the bytes. Fields
 * are nullable: an image whose `getimagesize()` failed returns `width=null,
 * height=null`; an audio file analysed without `ffprobe` returns
 * `durationSeconds=null`. The service decides what to persist — these nulls
 * are part of the contract, not error markers.
 */
final readonly class ExtractedMetadata
{
    public function __construct(
        public ?int $width,
        public ?int $height,
        public ?float $durationSeconds,
        public ?string $mime,
    ) {}
}
