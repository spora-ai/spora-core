<?php

declare(strict_types=1);

use Spora\Core\ConfigFactory;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;

beforeEach(function (): void {
    TestDatabaseFactory::boot();
});

/**
 * Keys a stored value can actually reach `Config` through.
 *
 * A `bootOnly` setting is skipped by `applySettingsOverlay()`, and a row written
 * for one would sit in the table without ever being read — the controller
 * refuses such a write, but the repository does not. `null` and an
 * empty-but-secret value both mean "delete the row" to `SettingsRepository::put()`,
 * so neither leaves anything to round-trip.
 */
function roundTrippableKeys(): array
{
    $registry = new SettingsRegistry(new Paths(BASE_PATH));

    return array_values(array_map(
        static fn(SettingDefinition $d): string => $d->key,
        array_filter(
            $registry->all(),
            static fn(SettingDefinition $d): bool => $d->bootOnly === false
                && $d->default !== null
                && !($d->secret && $registry->encode($d->default) === ''),
        ),
    ));
}

/**
 * The other half of `SettingsConsumptionTest`. That test proves every declared
 * key is read somewhere; this proves a value written through the admin API
 * reaches the `Config` the consumers read, on the last layer where it can be
 * shadowed.
 *
 * The `source` assertion is the one that carries the weight. Storing the
 * declared default would make the value assertion pass for a no-op overlay,
 * because the value the factory already produced is the value being asserted.
 */
it('resolves a stored value from the database layer, unchanged', function (string $key): void {
    $registry  = new SettingsRegistry(new Paths(BASE_PATH));
    $paths     = new Paths(BASE_PATH);
    $definition = $registry->get($key);
    $repository = new SettingsRepository(new SecurityManager(random_bytes(32)), $registry);

    $repository->put($definition, $definition->default, null);

    $config = (new ConfigFactory($registry, $paths))->create();
    $config->applySettingsOverlay($repository, $registry);

    expect($config->sourceOf($key))->toBe('database')
        ->and($config->get($key))->toEqual($definition->default);
})->with(roundTrippableKeys());
