<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\Config;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Paths;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Settings\SettingsRegistry;

/**
 * Regression test for the v0.13.x MEDIUMBLOB widening. Review Finding #1
 * of #183 originally shipped `max_bytes = 50 MiB` as the default config,
 * which crashed the `DatabaseAssetStore` factory on first boot. Each
 * test invokes the SHIPPED factory via reflection — calling a
 * copy-pasted closure in `tests/` doesn't register against SonarQube's
 * `new_coverage` metric on the `app/` body and would let this drift
 * re-appear past CI.
 */

/**
 * Container carrying only the `Config` the shipped factory reads its ceiling
 * from. The factory no longer consults a `'config'` array entry — it asks for
 * `Config::class` and reads `SPORA_ASSET_STORE_MAX_BYTES` off it.
 */
function buildContainerWithAssetStoreConfig(string $mode, int $maxBytes): Psr\Container\ContainerInterface
{
    $builder = new ContainerBuilder();
    $builder->useAutowiring(false);
    $builder->addDefinitions([
        Config::class => new Config([
            'SPORA_ASSET_STORE_MODE' => $mode,
            'SPORA_ASSET_STORE_MAX_BYTES' => $maxBytes,
        ]),
    ]);
    return $builder->build();
}

function shipDatabaseAssetStoreFactory(): Closure
{
    $ref = new ReflectionMethod(ContainerDefinitions::class, 'coreServiceDefinitions');
    return $ref->invoke(null)[DatabaseAssetStore::class];
}

test('shipped DatabaseAssetStore factory returns an instance when max_bytes equals the MEDIUMBLOB ceiling', function (): void {
    $c = buildContainerWithAssetStoreConfig('data_url', DatabaseAssetStore::MAX_BYTES);

    expect(shipDatabaseAssetStoreFactory()($c))->toBeInstanceOf(DatabaseAssetStore::class);
});

test('shipped DatabaseAssetStore factory throws InvalidArgumentException when max_bytes exceeds the MEDIUMBLOB ceiling', function (): void {
    $c = buildContainerWithAssetStoreConfig('data_url', DatabaseAssetStore::MAX_BYTES + 1);

    expect(fn() => shipDatabaseAssetStoreFactory()($c))
        ->toThrow(InvalidArgumentException::class, 'MEDIUMBLOB ceiling');
});

test('shipped asset_store config default is the MEDIUMBLOB ceiling', function (): void {
    // Pin the literal default from `SettingsRegistry` so a future drift
    // back to the old 50 MiB value is caught at CI, not on first boot.
    $registry = new SettingsRegistry(new Paths(BASE_PATH));

    expect($registry->get('SPORA_ASSET_STORE_MAX_BYTES')?->default)->toBe(DatabaseAssetStore::MAX_BYTES);
    expect($registry->get('SPORA_ASSET_STORE_MODE')?->default)->toBe('auto');
});

test('DATA_URL_MAX_BYTES matches DatabaseAssetStore::MAX_BYTES', function (): void {
    // The two constants are independent definitions of the same ceiling.
    // A drift lets an oversized asset slip through one check or the other.
    expect(MediaArchiveService::DATA_URL_MAX_BYTES)
        ->toBe(DatabaseAssetStore::MAX_BYTES);
});
