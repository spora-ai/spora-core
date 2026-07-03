<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Composite {@see AssetStore} that picks `data_url` for small payloads and
 * `local` for larger ones, based on a configurable byte threshold.
 *
 * The threshold is resolved once at construction time — the per-call
 * decision is a single `strlen()` comparison, so this impl is safe to use
 * on every tool invocation.
 *
 * This is the default mode (`SPORA_ASSET_STORE_MODE=auto`).
 */
final class AutoAssetStore implements AssetStore
{
    public function __construct(
        private readonly DataUrlAssetStore $dataUrl,
        private readonly LocalAssetStore $local,
        private readonly int $thresholdBytes,
    ) {}

    public function store(string $bytes, ?string $mime = null, ?string $filename = null): AssetReference
    {
        if (strlen($bytes) <= $this->thresholdBytes) {
            return $this->dataUrl->store($bytes, $mime, $filename);
        }
        return $this->local->store($bytes, $mime, $filename);
    }
}
