<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Models\MediaAsset;
use Spora\Services\LocalAssetStore;

/**
 * Byte-level access to a derivative's stored payload.
 *
 * A derivative is stored exactly like its parent — a BLOB in the `payload`
 * column under `data_url`, a file under `storage/assets/<token>.<ext>`
 * under `local` — so reading, rewriting and removing those bytes is a
 * concern separate from choosing a producer or tracking attribution.
 *
 * Invariant: the `local` layout mirrors {@see \Spora\Services\LocalAssetStore},
 * which owns the same layout for the original asset. It holds because
 * {@see pathFor()} resolves the `<ext>` half through
 * {@see LocalAssetStore::storedExtension()} over the SAME two row columns
 * (`mime_type`, `filename`) the writer was handed — not by re-deriving it
 * from the MIME alone, which is what put a `thumbnail-256` derivative at
 * `<token>.thumbnail-256` while this class looked for `<token>.webp` and
 * every local-mode image derivative 404'd. The condition is stated on
 * `storedExtension()`: the row's `filename` must be the name the bytes were
 * stored under, which every core writer satisfies. `DerivativePayloadStoreTest`
 * asserts the agreement by round-tripping real written bytes rather than a
 * literal path shape.
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
     *
     * The extension comes from the writer's own rule
     * ({@see LocalAssetStore::storedExtension()}) applied to the row's
     * `mime_type` + `filename`, so this reconstructs the exact name
     * `AssetStore::store()` produced rather than guessing it from the MIME.
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
        $ext = LocalAssetStore::storedExtension($asset->mime_type, $asset->filename);

        return (new \Spora\Core\Paths($this->basePath))->storage('assets')
            . '/' . $token . '.' . $ext;
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
