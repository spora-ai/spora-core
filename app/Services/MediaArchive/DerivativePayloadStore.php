<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Models\MediaAsset;

/**
 * Byte-level access to a derivative's stored payload.
 *
 * A derivative is stored exactly like its parent — a BLOB in the `payload`
 * column under `data_url`, a file under `storage/assets/<token>.<ext>`
 * under `local` — so reading, rewriting and removing those bytes is a
 * concern separate from choosing a producer or tracking attribution.
 *
 * Invariant: the `local` layout mirrors {@see \Spora\Services\LocalAssetStore},
 * which owns the same layout for the original asset. If the two drift, a
 * derivative written here cannot be read back by the original's reader. Both
 * resolve the extension from the same single map,
 * {@see MediaArchiveService::extensionForMime()}, so a new MIME only has to
 * be added once. The path shape is asserted in `DerivativePayloadStoreTest`.
 */
final readonly class DerivativePayloadStore
{
    public function __construct(
        private string $basePath,
    ) {}

    /**
     * The stored bytes, or '' when the payload cannot be read. Callers
     * treat empty and unreadable the same way: a derivative with no
     * readable content is not worth surfacing.
     */
    public function read(MediaAsset $derivative): string
    {
        if ($derivative->storage_mode === 'data_url') {
            return is_string($derivative->payload) ? $derivative->payload : '';
        }
        $path = $this->pathFor($derivative);
        if ($path === null || !is_file($path)) {
            return '';
        }

        return (string) $this->withSuppressedWarnings(
            static fn(): string|false => file_get_contents($path),
        );
    }

    /**
     * Overwrite the payload in place. The `asset_token` is deliberately
     * left alone: a fresh token would orphan the previous file on every
     * re-render.
     */
    public function rewrite(MediaAsset $derivative, string $bytes): void
    {
        if ($derivative->storage_mode === 'data_url') {
            $derivative->payload = $bytes;

            return;
        }
        $path = $this->pathFor($derivative);
        if ($path === null) {
            return;
        }
        $this->withSuppressedWarnings(static function () use ($path, $bytes): void {
            file_put_contents($path, $bytes, LOCK_EX);
        });
    }

    /**
     * Drop the payload. A `data_url` derivative's bytes live in the BLOB
     * column the row delete removes for us, and an `external` row has no
     * Spora-side file at all.
     */
    public function remove(MediaAsset $derivative): void
    {
        if ($derivative->storage_mode !== 'local') {
            return;
        }
        $path = $this->pathFor($derivative);
        if ($path === null) {
            return;
        }
        $this->withSuppressedWarnings(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });
    }
    /**
     * Absolute path of a `local`-mode asset's payload, or null when the
     * row carries no token to resolve one from.
     */
    public function pathFor(MediaAsset $asset): ?string
    {
        if ($asset->storage_mode !== 'local') {
            return null;
        }
        $token = $asset->asset_token;
        if (!is_string($token) || $token === '') {
            return null;
        }
        $ext = MediaArchiveService::extensionForMime($asset->mime_type);

        return (new \Spora\Core\Paths($this->basePath))->storage('assets')
            . '/' . $token . ($ext !== null ? '.' . $ext : '');
    }

    /**
     * Run a filesystem operation with E_WARNING suppressed. PHP 8.4+ no
     * longer fully honours `@` for file writes, and a missing directory
     * should not warn on the way to a caller that already accepts a no-op.
     */
    private function withSuppressedWarnings(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
