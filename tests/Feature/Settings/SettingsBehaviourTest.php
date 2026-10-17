<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;
use Spora\Core\Config;
use Spora\Core\ContainerDefinitions;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\ConfigController;
use Spora\Http\Exceptions\FeatureDisabledException;
use Spora\Http\PluginsController;
use Spora\Plugins\PluginLoader;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Symfony\Component\HttpFoundation\Request;

beforeEach(function (): void {
    TestDatabaseFactory::boot();
    $_ENV['SPORA_SECRET_KEY'] = base64_encode(random_bytes(32));
});

/**
 * @return array{0: SettingsRegistry, 1: SettingsRepository}
 */
function behaviourRepository(Paths $paths): array
{
    $registry = new SettingsRegistry($paths);

    return [$registry, new SettingsRepository(new SecurityManager(random_bytes(32)), $registry)];
}

/**
 * A container built the way production builds one, with the database layer of
 * precedence applied on top.
 *
 * The writer and the reader share one `SecurityManager`. Splitting them means a
 * secret setting cannot be decrypted on the way back in, and the overlay falls
 * back to the default without saying so — the exact failure this file exists to
 * rule out.
 */
function bootContainer(Paths $paths, SettingsRegistry $registry, SettingsRepository $repository): Container
{
    $builder = new ContainerBuilder();
    $builder->addDefinitions(ContainerDefinitions::all());

    // `Kernel` constructs the loader and registers it as an instance; nothing in
    // `ContainerDefinitions` can build one, because the plugin directories come
    // from `Paths`. Without this, every resolution of `PluginsService` fails.
    $loader = new PluginLoader([$paths->plugins()], $paths->storage('.plugins_stamp'));
    $loader->boot();
    $builder->addDefinitions([PluginLoader::class => $loader]);

    $container = $builder->build();
    $container->get(Config::class)->applySettingsOverlay($repository, $registry);

    return $container;
}

/** Store a value the way the admin API would, then boot a container that reads it. */
function containerWith(string $key, mixed $value): Container
{
    $paths = new Paths(BASE_PATH);
    [$registry, $repository] = behaviourRepository($paths);
    $repository->put($registry->get($key), $value, null);

    return bootContainer($paths, $registry, $repository);
}

function configPayload(Container $container): array
{
    return json_decode(
        $container->get(ConfigController::class)->index()->getContent(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/**
 * The other half of `SettingsRoundTripTest`.
 *
 * That test stops at `Config`: it proves a stored value reaches the object the
 * consumers read. It cannot fail when a consumer reads the right key at the
 * wrong point in its own construction — the value is in `Config`, and nothing
 * looks at it. The gate would stay green while an operator watched a setting sit
 * in the database being ignored.
 *
 * Each case resolves the real service out of a real container and calls it. The
 * claim is behavioural: the stored value changes what the application does, not
 * merely what it has read.
 *
 * `PluginsController`'s own tests pass the flag in as a constructor literal, so
 * they already prove the controller honours it. What nobody proved is that the
 * flag ever arrives from the database.
 */
it('advertises the stored registration flag instead of the default', function (): void {
    // The registry default is true; storing false must reverse what the SPA is
    // told, or the "Create account" link stays visible with registration shut.
    expect(configPayload(containerWith('SPORA_ALLOW_REGISTRATION', false))['allow_registration'])
        ->toBeFalse();

    expect(configPayload(containerWith('SPORA_ALLOW_REGISTRATION', true))['allow_registration'])
        ->toBeTrue();
});

it('restores the registry default once a stored setting is cleared', function (): void {
    $paths = new Paths(BASE_PATH);
    [$registry, $repository] = behaviourRepository($paths);
    $definition = $registry->get('SPORA_ALLOW_REGISTRATION');

    $repository->put($definition, false, null);
    expect(configPayload(bootContainer($paths, $registry, $repository))['allow_registration'])
        ->toBeFalse();

    // The "Clear" button in the settings overlay. The row has to go, not be
    // overwritten with the default — otherwise `source` would still read
    // `database` and the UI would claim a stored value that is only the
    // registry default wearing a database row's clothes.
    $repository->delete($definition->group, $definition->key);

    expect(configPayload(bootContainer($paths, $registry, $repository))['allow_registration'])
        ->toBeTrue();
});

it('closes the catalog endpoint when the stored setting disables it', function (): void {
    $response = containerWith('SPORA_PLUGIN_CATALOG_ENABLED', false)
        ->get(PluginsController::class)
        ->catalog(Request::create('/api/v1/plugins/catalog'));

    expect($response->getStatusCode())->toBe(404);
    expect(json_decode((string) $response->getContent(), true)['error']['message'])
        ->toBe('Plugin catalog is disabled.');
});

it('opens and closes the plugin install gate from the stored setting', function (): void {
    // Both directions live in one case on purpose. The registry default is
    // `false`, so a test asserting only the refusal passes against a build that
    // never reads the database — which is the failure this file is here to
    // catch, not a hypothetical.
    $open = containerWith('SPORA_PLUGIN_INSTALL_ENABLED', true)->get(PluginsController::class);

    // An empty body fails validation, which is the point: the gate is behind us
    // and Composer is never reached, so this asserts on the gate rather than on
    // whether the host happens to have a Composer binary.
    expect($open->store(Request::create('/api/v1/plugins', 'POST'))->getStatusCode())
        ->toBe(400);

    $shut = containerWith('SPORA_PLUGIN_INSTALL_ENABLED', false)->get(PluginsController::class);

    expect(fn() => $shut->store(Request::create('/api/v1/plugins', 'POST')))
        ->toThrow(FeatureDisabledException::class);
});
