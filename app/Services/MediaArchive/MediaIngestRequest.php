<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use InvalidArgumentException;

/**
 * Readonly input DTO for {@see MediaArchiveService::ingest()}.
 *
 * Plugins populate one of `bytes` / `hex` / `base64` / `url` to indicate
 * the source; everything else is optional context the core can't infer
 * (agent/task linkage, source attribution, semantic tags).
 *
 * The `mediaType` discriminator is optional — when null the service derives
 * it from the sniffed MIME type. Pre-setting it lets plugins short-circuit
 * sniffing for cases where they already know the format (e.g. a tool that
 * only ever produces PNG images).
 */
final readonly class MediaIngestRequest
{
    public function __construct(
        public ?string $bytes = null,
        public ?string $hex = null,
        public ?string $base64 = null,
        public ?string $url = null,
        public ?string $mime = null,
        public ?string $filename = null,
        public ?MediaType $mediaType = null,
        public ?int $agentId = null,
        public ?int $taskId = null,
        public ?int $toolCallId = null,
        public ?int $userId = null,
        public ?string $pluginSlug = null,
        public ?string $toolName = null,
        public ?string $prompt = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?float $durationSeconds = null,
        public ?int $byteSize = null,
        /** @var array<string>|null */
        public ?array $tags = null,
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
        /**
         * Source of the ingest — `'upload'` for user uploads, `'tool'` for
         * plugin-generated media. Defaults to `'tool'` to preserve the
         * historical contract with existing plugin callers; the upload
         * controller passes `'upload'`.
         */
        public string $uploadSource = 'tool',
        public ?string $publicAccessToken = null,
    ) {
        // Empty strings are not a valid source — only non-empty payloads
        // count toward the "exactly one" invariant. Matches the rest of
        // the codebase's "empty payload is invalid" convention (e.g.
        // {@see \Spora\Plugins\Concerns\StoresBinaryAssets::embedHex}).
        $hasBytes  = is_string($bytes) && $bytes !== '';
        $hasHex    = is_string($hex) && $hex !== '';
        $hasBase64 = is_string($base64) && $base64 !== '';
        $hasUrl    = is_string($url) && $url !== '';

        $sourceCount = (int) $hasBytes + (int) $hasHex + (int) $hasBase64 + (int) $hasUrl;
        if ($sourceCount !== 1) {
            throw new InvalidArgumentException(
                'MediaIngestRequest requires exactly one non-empty source among bytes, hex, base64, or url.',
            );
        }
    }
}
