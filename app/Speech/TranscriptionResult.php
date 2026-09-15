<?php

declare(strict_types=1);

namespace Spora\Speech;

/**
 * Provider-returned transcript.
 *
 * Provider-agnostic value object — the controller layer maps this onto
 * the response wire shape (`text`, `language`, `duration_ms`) and onto
 * the `media_assets.transcript` / `media_assets.transcript_language`
 * columns for cache-back.
 *
 * `metadata` is a forward-compat bag for provider-specific extras
 * (word timestamps, diarization segments, confidence scores). Spora
 * core does not interpret it; consumers that need these fields read
 * the bag directly.
 */
final readonly class TranscriptionResult
{
    /**
     * @param string                   $text       The transcript.
     * @param string|null              $language   BCP-47 detected language, or null.
     * @param float|null               $durationMs Audio duration in milliseconds, if the provider knows.
     * @param array<string, mixed>     $metadata   Forward-compat bag.
     */
    public function __construct(
        public string $text,
        public ?string $language = null,
        public ?float $durationMs = null,
        public array $metadata = [],
    ) {}
}
