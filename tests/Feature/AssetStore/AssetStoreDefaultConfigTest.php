<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Paths;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;

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
 * Cheap throwaway container resolving only `Paths::class` — matches
 * the shape `configDefinition()` reads from `$c->get(...)`. Mirrors
 * `makeFakeContainer()` in ContainerDefinitionsTest.php.
 */
function mediumblobFakeContainer(): Psr\Container\ContainerInterface
{
    return new class (new Paths(BASE_PATH)) implements Psr\Container\ContainerInterface {
        public function __construct(private readonly Paths $paths) {}
        public function get(string $id): mixed
        {
            if ($id === Paths::class) {
                return $this->paths;
            }
            throw new RuntimeException("Unexpected container lookup: $id");
        }
        public function has(string $id): bool
        {
            return $id === Paths::class;
        }
    };
}

function buildContainerWithAssetStoreConfig(array $assetStoreOverride): Psr\Container\ContainerInterface
{
    $builder = new ContainerBuilder();
    $builder->useAutowiring(false);
    $builder->addDefinitions([
        'config' => ['asset_store' => $assetStoreOverride],
    ]);
    return $builder->build();
}

function shipDatabaseAssetStoreFactory(): Closure
{
    $ref = new ReflectionMethod(ContainerDefinitions::class, 'coreServiceDefinitions');
    return $ref->invoke(null)[DatabaseAssetStore::class];
}

test('shipped DatabaseAssetStore factory returns an instance when max_bytes equals the MEDIUMBLOB ceiling', function (): void {
    $c = buildContainerWithAssetStoreConfig([
        'mode'      => 'data_url',
        'max_bytes' => DatabaseAssetStore::MAX_BYTES,
    ]);

    expect(shipDatabaseAssetStoreFactory()($c))->toBeInstanceOf(DatabaseAssetStore::class);
});

test('shipped DatabaseAssetStore factory throws InvalidArgumentException when max_bytes exceeds the MEDIUMBLOB ceiling', function (): void {
    $c = buildContainerWithAssetStoreConfig([
        'mode'      => 'data_url',
        'max_bytes' => DatabaseAssetStore::MAX_BYTES + 1,
    ]);

    expect(fn() => shipDatabaseAssetStoreFactory()($c))
        ->toThrow(InvalidArgumentException::class, 'MEDIUMBLOB ceiling');
});

test('shipped asset_store config default is the MEDIUMBLOB ceiling', function (): void {
    // Pin the literal default from `configDefinition()` so a future drift
    // back to the old 50 MiB value is caught at CI, not on first boot.
    $ref = new ReflectionMethod(ContainerDefinitions::class, 'configDefinition');
    $config = ($ref->invoke(null)['config'])(mediumblobFakeContainer());

    expect($config['asset_store']['max_bytes'])->toBe(DatabaseAssetStore::MAX_BYTES);
    expect($config['asset_store']['mode'])->toBe('auto');
});

test('DATA_URL_MAX_BYTES matches DatabaseAssetStore::MAX_BYTES', function (): void {
    // The two constants are independent definitions of the same ceiling.
    // A drift lets an oversized asset slip through one check or the other.
    expect(MediaArchiveService::DATA_URL_MAX_BYTES)
        ->toBe(DatabaseAssetStore::MAX_BYTES);
});
