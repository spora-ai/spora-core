<?php

declare(strict_types=1);

namespace Spora\Plugins\Concerns;

use InvalidArgumentException;
use LogicException;
use Spora\Services\AssetStore;

/**
 * Opt-in trait for plugin tool classes. Removes the `hex2bin` +
 * `AssetStore::store()` boilerplate from each tool.
 *
 * Plugins that can't `use` a trait (e.g. their base class is sealed or
 * already consumed a conflicting slot) can call
 * {@see AssetStore::store()} directly plus the static helpers in
 * {@see \Spora\Tools\MediaEmbed}. The trait is a convenience, not a
 * requirement.
 *
 * The `setAssetStore()` setter is invoked by PHP-DI at construction
 * time (auto-wired from the container). Plugins that build tools via
 * reflection or hand-roll their own factories should call it explicitly.
 */
trait StoresBinaryAssets
{
    private ?AssetStore $assetStore = null;

    /**
     * Called by PHP-DI auto-resolution when the container constructs the
     * plugin's tool class. Safe to call multiple times; the last write wins.
     */
    public function setAssetStore(AssetStore $store): void
    {
        $this->assetStore = $store;
    }

    public function assetStore(): AssetStore
    {
        if ($this->assetStore === null) {
            throw new LogicException(
                'AssetStore has not been injected into ' . static::class
                . '. Did the DI container miss the auto-wiring?',
            );
        }
        return $this->assetStore;
    }

    /**
     * Decode a hex payload (with odd-length guard) and persist via
     * {@see AssetStore::store()}. Returns the canonical
     * `[url, mode]` pair so callers can decide what to do with `mode`
     * (typically: surface in `ToolResult::$data['asset_mode']` for the
     * UI).
     *
     * @return array{0: string, 1: string}  [url, mode]
     */
    protected function embedHex(string $hex, string $mime, string $filename): array
    {
        if (strlen($hex) % 2 !== 0) {
            throw new InvalidArgumentException(
                'Hex payload has odd length (' . strlen($hex) . ' chars); refusing to decode.',
            );
        }
        $bytes = (string) hex2bin($hex);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('Hex payload decoded to empty bytes.');
        }
        $ref = $this->assetStore()->store($bytes, mime: $mime, filename: $filename);
        return [$ref->url, $ref->mode];
    }
}
