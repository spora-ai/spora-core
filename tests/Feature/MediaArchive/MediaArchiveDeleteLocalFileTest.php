<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use RuntimeException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\DerivativePayloadStore;
use Spora\Services\MediaArchive\MediaArchiveException;
use Tests\Support\MediaArchiveTestSupport;

/**
 * Deleting an asset has to take its bytes with it.
 *
 * `media_assets.payload` is a BLOB for a `data_url` row, so the row delete
 * takes those bytes with it. A `local` row's bytes live in a file under
 * `<storage>/assets/`, and nothing else points at that file once the row is
 * gone — so a delete that only dropped the row left the bytes on disk for
 * ever, with no row left to account for them.
 *
 * The suffix the file is stored under is the writer's own rule
 * ({@see \Spora\Services\LocalAssetStore::storedExtension()}), which the
 * row's `filename` decides over its MIME. The delete path has to ask the
 * same question the writer did, or it unlinks a path that was never
 * written.
 */
beforeEach(function (): void {
    $dir = sys_get_temp_dir() . '/spora-delete-local-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$dir}");
    $_ENV['SPORA_STORAGE_DIR']    = $dir;
    $_SERVER['SPORA_STORAGE_DIR'] = $dir;

    $GLOBALS['deleteLocalDir'] = $dir;
});

afterEach(function (): void {
    $dir = $GLOBALS['deleteLocalDir'] ?? null;
    if (is_string($dir)) {
        array_map('unlink', glob($dir . '/assets/*') ?: []);
        @rmdir($dir . '/assets');
        @rmdir($dir);
        unset($GLOBALS['deleteLocalDir']);
    }

    putenv('SPORA_STORAGE_DIR');
    unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
});

/**
 * An `auto` store with threshold 1, so every `store()` call lands in
 * `local` mode — the only mode with bytes on disk.
 */
function deleteLocalStore(): AutoAssetStore
{
    return new AutoAssetStore(
        new DatabaseAssetStore(50 * 1024 * 1024),
        new LocalAssetStore(
            new Paths(BASE_PATH),
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
        1,
    );
}

/**
 * A `local`-mode asset with real bytes on disk, written through the same
 * store the delete path has to clean up.
 *
 * The row is hand-built rather than ingested because ingest mints its own
 * `md` derivative, which is a different concern from the parent's own
 * bytes.
 */
function deleteLocalAsset(AutoAssetStore $store, string $filename = 'holiday.png'): MediaAsset
{
    $bytes = 'bytes of an image that never made it to a decoder';
    $ref   = $store->store($bytes, mime: 'image/png', filename: $filename);
    expect($ref->mode)->toBe('local');

    return MediaAsset::create([
        'id'           => testGenerateUuidV4(),
        'asset_url'    => '/api/v1/assets/' . testGenerateUuidV4() . '.png',
        'storage_mode' => 'local',
        'media_type'   => 'image',
        'mime_type'    => 'image/png',
        'byte_size'    => strlen($bytes),
        'asset_token'  => $ref->token,
        'filename'     => $filename,
    ]);
}

/**
 * A `data_url`-mode asset: its bytes live in the row, not on disk.
 */
function deleteLocalDataUrlAsset(): MediaAsset
{
    $bytes = 'bytes of an image that lives in the row';

    return MediaAsset::create([
        'id'           => testGenerateUuidV4(),
        'asset_url'    => '/api/v1/assets/' . testGenerateUuidV4() . '.png',
        'storage_mode' => 'data_url',
        'media_type'   => 'image',
        'mime_type'    => 'image/png',
        'byte_size'    => strlen($bytes),
        'payload'      => $bytes,
        'filename'     => 'inline.png',
    ]);
}

describe('deleting an asset takes its bytes with it', function (): void {
    it('removes the file of a local asset and the row that pointed at it', function (): void {
        $store   = deleteLocalStore();
        $service = MediaArchiveTestSupport::buildService($store);
        $asset   = deleteLocalAsset($store);

        $path = (new DerivativePayloadStore(BASE_PATH))->pathFor($asset);
        expect($path)->not->toBeNull();
        expect(is_file($path))->toBeTrue();

        $service->delete($asset->id);

        expect(MediaAsset::query()->find($asset->id))->toBeNull();
        expect(is_file($path))->toBeFalse();
        expect(glob(($GLOBALS['deleteLocalDir'] ?? '') . '/assets/*'))->toBe([]);
    });

    it('unlinks the file the writer wrote, not the one the MIME implies', function (): void {
        // The row's `filename` wins over the MIME, so the file is
        // `<token>.thumbnail-256` and not `<token>.png`. A delete that
        // resolved the suffix from the MIME alone would unlink a path that
        // was never written and leave the real one behind.
        $store   = deleteLocalStore();
        $service = MediaArchiveTestSupport::buildService($store);
        $asset   = deleteLocalAsset($store, 'holiday.thumbnail-256');

        $path = (new DerivativePayloadStore(BASE_PATH))->pathFor($asset);
        expect($path)->toEndWith($asset->asset_token . '.thumbnail-256');
        expect(is_file($path))->toBeTrue();

        $service->delete($asset->id);

        expect(is_file($path))->toBeFalse();
    });

    it('succeeds when the file of a local asset is already gone', function (): void {
        $store   = deleteLocalStore();
        $service = MediaArchiveTestSupport::buildService($store);
        $asset   = deleteLocalAsset($store);

        $path = (new DerivativePayloadStore(BASE_PATH))->pathFor($asset);
        expect($path)->not->toBeNull();
        unlink($path);

        expect(function () use ($service, $asset): void {
            $service->delete($asset->id);
        })->not->toThrow(RuntimeException::class);

        expect(MediaAsset::query()->find($asset->id))->toBeNull();
    });

    it('keeps the row when the filesystem refuses to remove the file', function (): void {
        // The bytes are on disk and the row is the only thing pointing at
        // them. A row deleted anyway leaves the bytes on disk with nothing
        // to account for them and no way to retry: the second delete finds
        // no row and returns.
        //
        // The assets directory is made read-only, so `unlink()` fails —
        // removing an entry needs write permission on the directory that
        // holds it, not on the file.
        $store   = deleteLocalStore();
        $service = MediaArchiveTestSupport::buildService($store);
        $asset   = deleteLocalAsset($store);

        $path = (new DerivativePayloadStore(BASE_PATH))->pathFor($asset);
        expect(is_file($path))->toBeTrue();

        $assetsDir = dirname((string) $path);
        chmod($assetsDir, 0555);
        clearstatcache(true, $assetsDir);

        try {
            // A process that holds CAP_DAC_OVERRIDE reads and writes
            // regardless of the permissions, so this environment cannot
            // express the failure the test is about.
            if (is_writable($assetsDir)) {
                $this->markTestSkipped(
                    'This process can write to a read-only directory, so the refused unlink cannot be exercised here.',
                );
            }

            expect(fn() => $service->delete($asset->id))
                ->toThrow(MediaArchiveException::class);

            // The row survives, so a retry can free the bytes once the
            // filesystem allows it.
            expect(MediaAsset::query()->find($asset->id))->not->toBeNull();
            expect(is_file($path))->toBeTrue();
        } finally {
            chmod($assetsDir, 0755);
        }
    });

    it('removes the row of a data_url asset and leaves the assets directory alone', function (): void {
        // A `data_url` asset's bytes live in the column the row delete
        // drops, so there is no file to unlink — and no other asset's file
        // may be touched on the way.
        $store   = deleteLocalStore();
        $service = MediaArchiveTestSupport::buildService($store);
        $asset   = deleteLocalDataUrlAsset();
        $other   = deleteLocalAsset($store);

        $otherPath = (new DerivativePayloadStore(BASE_PATH))->pathFor($other);
        expect(is_file($otherPath))->toBeTrue();

        $service->delete($asset->id);

        expect(MediaAsset::query()->find($asset->id))->toBeNull();
        expect(is_file($otherPath))->toBeTrue();
    });

    it('takes the derivatives of a local asset with it', function (): void {
        // The existing behaviour: the derivative rows and their files go
        // before the parent, or the parent delete cascades the join rows
        // away and orphans the derivative rows.
        $store   = deleteLocalStore();
        $service = MediaArchiveTestSupport::buildService($store);
        $parent  = deleteLocalAsset($store);

        $derivative = deleteLocalAsset($store, 'holiday.thumbnail-256');
        MediaDerivative::create([
            'id'                 => testGenerateUuidV4(),
            'parent_id'          => $parent->id,
            'derivative_id'      => $derivative->id,
            'format'             => 'thumbnail-256',
            'producer_plugin'    => 'tests',
            'producer_operation' => 'thumbnail',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $payloads = new DerivativePayloadStore(BASE_PATH);
        $parentPath     = (string) $payloads->pathFor($parent);
        $derivativePath = (string) $payloads->pathFor($derivative);
        expect(is_file($parentPath))->toBeTrue();
        expect(is_file($derivativePath))->toBeTrue();

        $service->delete($parent->id);

        expect(MediaAsset::query()->find($parent->id))->toBeNull();
        expect(MediaAsset::query()->find($derivative->id))->toBeNull();
        expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(0);
        expect(is_file($parentPath))->toBeFalse();
        expect(is_file($derivativePath))->toBeFalse();
        expect(glob(($GLOBALS['deleteLocalDir'] ?? '') . '/assets/*'))->toBe([]);
    });

    it('keeps the whole asset when a derivative\'s bytes survive the unlink', function (): void {
        // The derivative's bytes are on disk and its row is the only thing
        // pointing at them. A parent deleted anyway takes the derivative's
        // row with it — and the bytes are then on disk with nothing left to
        // account for them.
        //
        // The parent's own bytes go last, so a parent whose derivative
        // survived keeps its own file too: a row that points at a file
        // that is gone is a broken asset, not a deleted one.
        $store   = deleteLocalStore();
        $service = MediaArchiveTestSupport::buildService($store);
        $parent  = deleteLocalAsset($store);

        $derivative = deleteLocalAsset($store, 'holiday.thumbnail-256');
        MediaDerivative::create([
            'id'                 => testGenerateUuidV4(),
            'parent_id'          => $parent->id,
            'derivative_id'      => $derivative->id,
            'format'             => 'thumbnail-256',
            'producer_plugin'    => 'tests',
            'producer_operation' => 'thumbnail',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $payloads = new DerivativePayloadStore(BASE_PATH);
        $parentPath     = (string) $payloads->pathFor($parent);
        $derivativePath = (string) $payloads->pathFor($derivative);

        $assetsDir = dirname($derivativePath);
        chmod($assetsDir, 0555);
        clearstatcache(true, $assetsDir);

        try {
            if (is_writable($assetsDir)) {
                $this->markTestSkipped(
                    'This process can write to a read-only directory, so the refused unlink cannot be exercised here.',
                );
            }

            expect(fn() => $service->delete($parent->id))
                ->toThrow(MediaArchiveException::class);

            // The parent row and its bytes stay, and the derivative's row
            // and join row stay with its bytes — a derivative whose join
            // row is gone resurfaces as a stray top-level library asset.
            expect(MediaAsset::query()->find($parent->id))->not->toBeNull();
            expect(MediaAsset::query()->find($derivative->id))->not->toBeNull();
            expect(MediaDerivative::query()->where('parent_id', $parent->id)->count())->toBe(1);
            expect(is_file($parentPath))->toBeTrue();
            expect(is_file($derivativePath))->toBeTrue();
        } finally {
            chmod($assetsDir, 0755);
        }
    });
});
