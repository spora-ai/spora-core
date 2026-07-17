<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use InvalidArgumentException;

/**
 * Decodes the inline (non-URL) input forms accepted by
 * {@see MediaArchiveService::ingest()}: raw bytes, hex, or base64.
 *
 * Extracted from `MediaArchiveService` so the orchestrator stays under the
 * 20-method Sonar threshold and the decoder is independently unit-testable.
 *
 * Failures raise {@see InvalidArgumentException} so the caller (typically a
 * plugin tool) can surface a meaningful error to the LLM rather than
 * silently swallowing a malformed payload.
 */
final class MediaIngestDecoder
{
    /**
     * Resolve an ingest request's inline source to raw bytes. Returns
     * null when the request is URL-driven (no inline payload present),
     * so the caller can branch to the URL pipeline.
     */
    public function decodeInline(MediaIngestRequest $request): ?string
    {
        if ($request->bytes !== null && $request->bytes !== '') {
            return $request->bytes;
        }
        if ($request->hex !== null && $request->hex !== '') {
            return $this->decodeHex($request->hex);
        }
        if ($request->base64 !== null && $request->base64 !== '') {
            return $this->decodeBase64($request->base64);
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException When the hex length is odd or the string is not valid hex.
     */
    public function decodeHex(string $hex): string
    {
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 2 !== 0) {
            throw new InvalidArgumentException('Hex payload has odd length.');
        }
        if (! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Hex payload is not valid hex.');
        }
        $decoded = hex2bin($hex);
        if ($decoded === false) {
            throw new InvalidArgumentException('Hex payload is not valid hex.');
        }

        return $decoded;
    }

    /**
     * @throws InvalidArgumentException When the string is not valid strict base64.
     */
    public function decodeBase64(string $payload): string
    {
        $decoded = base64_decode($payload, strict: true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Base64 payload is not valid base64.');
        }

        return $decoded;
    }
}
