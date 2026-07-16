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
        $source = $this->pickInlineSource($request);
        if ($source === null) {
            return null;
        }

        return match ($source['kind']) {
            'bytes'  => $source['value'],
            'hex'    => $this->decodeHex($source['value']),
            'base64' => $this->decodeBase64($source['value']),
        };
    }

    /**
     * Identify which inline source field carries the payload, in priority
     * order (bytes > hex > base64). Returns null when none is set, so
     * the caller can branch to the URL pipeline.
     *
     * @return array{kind: 'bytes'|'hex'|'base64', value: string}|null
     */
    private function pickInlineSource(MediaIngestRequest $request): ?array
    {
        $candidates = [
            ['kind' => 'bytes',  'value' => $request->bytes],
            ['kind' => 'hex',    'value' => $request->hex],
            ['kind' => 'base64', 'value' => $request->base64],
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate['value']) && $candidate['value'] !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException When the hex length is odd or the string is not valid hex.
     */
    public function decodeHex(string $hex): string
    {
        if (strlen($hex) % 2 !== 0) {
            throw new InvalidArgumentException('Hex payload has odd length.');
        }
        $decoded = @hex2bin($hex);
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
