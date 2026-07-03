<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Inline-only {@see AssetStore}. Returns a `data:<mime>;base64,…` URI
 * without touching disk.
 *
 * Pros: zero-config, zero-dependency on filesystem or HTTP routes, no auth
 *       surface. Works in any context where the resulting URL is embedded
 *       in HTML the user's browser can render.
 * Cons: payload is duplicated into the message bubble HTML (and the chat
 *       history row in the DB). For multi-megabyte payloads this bloats
 *       the DOM and slows render. Use {@see LocalAssetStore} for those.
 */
final class DataUrlAssetStore implements AssetStore
{
    public function __construct(
        private readonly int $maxBytes = 50 * 1024 * 1024,
    ) {}

    public function store(string $bytes, ?string $mime = null, ?string $filename = null): AssetReference
    {
        $size = strlen($bytes);
        if ($size > $this->maxBytes) {
            throw new AssetTooLargeException(sprintf(
                'Asset of %d bytes exceeds DataUrlAssetStore ceiling of %d bytes. '
                    . 'Switch asset_store.mode to "local" or "auto" to handle larger payloads.',
                $size,
                $this->maxBytes,
            ));
        }
        $resolvedMime = $mime !== null && $mime !== '' ? $mime : 'application/octet-stream';
        return new AssetReference(
            url: 'data:' . $resolvedMime . ';base64,' . base64_encode($bytes),
            mode: 'data_url',
        );
    }
}
