<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Stores an arbitrary binary blob (audio, video, image, document) produced
 * by a tool and returns a reference that can be embedded into a
 * {@see \Spora\Tools\ValueObjects\ToolResult}'s content markdown.
 *
 * The chosen implementation is wired by the DI container from the
 * `asset_store.mode` config key. Plugins should depend on this interface,
 * never on a concrete class — the operator's mode setting is opaque.
 *
 * Available modes:
 *   - `data_url` — payload is returned inline as `data:<mime>;base64,…`. No
 *                  disk write. Suitable for small payloads.
 *   - `local`    — payload is written to `<storage>/assets/<token>.<ext>` and
 *                  the returned URL points at `GET /api/v1/assets/<token>.<ext>`,
 *                  which is served by {@see \Spora\Http\AssetController} after
 *                  validating a daily-rotating HMAC token.
 *   - `auto`     — delegates to `data_url` for payloads at or below
 *                  `asset_store.auto_threshold_bytes`, otherwise `local`.
 *
 * The default implementation enforces `asset_store.max_bytes` and throws
 * {@see AssetTooLargeException} if exceeded.
 */
interface AssetStore
{
    /**
     * @param string      $bytes    Raw binary payload. NOT hex-encoded —
     *                              callers must run {@see hex2bin()} first if
     *                              the upstream API returned a hex string.
     * @param string|null $mime     MIME hint (e.g. `audio/mpeg`, `video/mp4`).
     *                              Required by `data_url` mode. In `local` mode
     *                              it determines the `Content-Type` header
     *                              served by the route.
     * @param string|null $filename Original filename — only used by `local`
     *                              mode to pick a file extension. Ignored by
     *                              `data_url` mode.
     *
     * @throws AssetTooLargeException If `$bytes` exceeds the configured max.
     */
    public function store(string $bytes, ?string $mime = null, ?string $filename = null): AssetReference;
}
