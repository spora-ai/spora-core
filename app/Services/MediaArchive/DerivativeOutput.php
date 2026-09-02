<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * Immutable result of {@see MediaDerivativeProducerInterface::produce()}.
 *
 * Holds the freshly produced derivative's bytes plus enough media
 * metadata to populate the new `media_assets` row's `mime_type`,
 * `width`, `height`, and `duration_seconds` columns. Optional fields
 * stay null when the producer doesn't know them.
 */
final class DerivativeOutput
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?float $durationSeconds = null,
    ) {}
}
