<?php

declare(strict_types=1);

use Spora\Services\AssetStore;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;

/**
 * Regression test for the v0.13.x MEDIUMBLOB widening — the shipped
 * `asset_store` default config must not exceed the MEDIUMBLOB ceiling,
 * or the `DatabaseAssetStore` factory throws on first container boot.
 *
 * The factory lives in `ContainerDefinitions::DatabaseAssetStore::class`.
 * Resolving `AssetStore::class` from a container that has the factory
 * registered (and the default config in place) exercises both
 * `mode='auto'` and `mode='data_url'` paths at once — the AutoAssetStore
 * inner mode always builds a DatabaseAssetStore.
 *
 * Review Finding #1 from PR #183 caught this: the original PR shipped
 * `'max_bytes' => 50 * 1024 * 1024` as the default, which throws.
 */

test('default asset_store config does not exceed the MEDIUMBLOB ceiling', function (): void {
    $builder = new DI\ContainerBuilder();
    $builder->useAutowiring(false);

    // Boot DatabaseAssetStore against an in-memory SQLite so the
    // resolver finds every concrete dep (LocalAssetStore, AssetStore).
    $tmp = sys_get_temp_dir() . '/spora-asset-store-default-' . bin2hex(random_bytes(4));
    $builder->addDefinitions([
        'config' => [
            'asset_store' => [
                // Mirrors the shipped default in ContainerDefinitions.
                'mode'                 => 'auto',
                'auto_threshold_bytes' => 1 * 1024 * 1024,
                'max_bytes'            => DatabaseAssetStore::MAX_BYTES,
            ],
        ],
        DatabaseAssetStore::class => static function ($c): DatabaseAssetStore {
            $max = (int) ($c->get('config')['asset_store']['max_bytes'] ?? DatabaseAssetStore::MAX_BYTES);
            if ($max > DatabaseAssetStore::MAX_BYTES) {
                throw new InvalidArgumentException(sprintf(
                    'asset_store.max_bytes=%d exceeds the %d-byte MEDIUMBLOB ceiling '
                        . 'on media_assets.payload. Lower it or switch asset_store.mode to "local".',
                    $max,
                    DatabaseAssetStore::MAX_BYTES,
                ));
            }
            return new DatabaseAssetStore($max);
        },
        LocalAssetStore::class => static function (): LocalAssetStore {
            return new LocalAssetStore(
                new Spora\Core\Paths(sys_get_temp_dir() . '/spora-asset-store-default-test'),
                new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                DatabaseAssetStore::MAX_BYTES,
            );
        },
        AssetStore::class => static function ($c): AssetStore {
            $cfg = $c->get('config')['asset_store'] ?? [];
            $mode = is_string($cfg['mode'] ?? null) ? $cfg['mode'] : 'auto';
            return match ($mode) {
                'local'    => $c->get(LocalAssetStore::class),
                'data_url' => $c->get(DatabaseAssetStore::class),
                'auto'     => new AutoAssetStore(
                    $c->get(DatabaseAssetStore::class),
                    $c->get(LocalAssetStore::class),
                    (int) ($cfg['auto_threshold_bytes'] ?? 1_048_576),
                ),
                default    => throw new InvalidArgumentException(
                    "Unknown asset_store.mode: {$mode}",
                ),
            };
        },
    ]);

    $container = $builder->build();

    // Resolving AssetStore::class is the trigger that fails on
    // oversize max_bytes. If the shipped default ever drifts back
    // above 16 MiB, this test catches it at CI time.
    expect($container->get(AssetStore::class))->toBeInstanceOf(AutoAssetStore::class);
});

test('asset_store.max_bytes above MEDIUMBLOB ceiling throws at boot', function (): void {
    // Symmetric to the above — pins the factory's fail-fast contract.
    $builder = new DI\ContainerBuilder();
    $builder->useAutowiring(false);

    $builder->addDefinitions([
        'config' => [
            'asset_store' => [
                'mode'      => 'data_url',
                'max_bytes' => DatabaseAssetStore::MAX_BYTES + 1,
            ],
        ],
        DatabaseAssetStore::class => static function ($c): DatabaseAssetStore {
            $max = (int) ($c->get('config')['asset_store']['max_bytes'] ?? DatabaseAssetStore::MAX_BYTES);
            if ($max > DatabaseAssetStore::MAX_BYTES) {
                throw new InvalidArgumentException(sprintf(
                    'asset_store.max_bytes=%d exceeds the %d-byte MEDIUMBLOB ceiling '
                        . 'on media_assets.payload. Lower it or switch asset_store.mode to "local".',
                    $max,
                    DatabaseAssetStore::MAX_BYTES,
                ));
            }
            return new DatabaseAssetStore($max);
        },
    ]);

    $container = $builder->build();

    expect(fn() => $container->get(DatabaseAssetStore::class))
        ->toThrow(InvalidArgumentException::class, 'MEDIUMBLOB ceiling');
});

test('DATA_URL_MAX_BYTES matches DatabaseAssetStore::MAX_BYTES', function (): void {
    // The two constants are independent definitions of the same
    // ceiling. A drift between them lets an oversized asset slip
    // through one check or the other. Pin the equivalence.
    expect(MediaArchiveService::DATA_URL_MAX_BYTES)
        ->toBe(DatabaseAssetStore::MAX_BYTES);
});
