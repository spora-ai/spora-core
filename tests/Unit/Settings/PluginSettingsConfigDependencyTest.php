<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Spora\Core\Config;
use Spora\Core\ContainerDefinitions;
use Spora\Plugins\PluginLoader;
use Spora\Settings\SettingsRegistry;

/**
 * A plugin's settings provider may depend on the configuration.
 *
 * `spora-plugin-social`'s provider does: it reads the app URL and the
 * platform credentials to describe its settings. Resolving such a provider
 * while the configuration is being built closes the cycle
 * `Config → provider → Config`, the container refuses it, and the provider's
 * settings vanish — silently, because the registry swallows the failure.
 */
function configDependentSettingsContainer(): ContainerInterface
{
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_config_dependent_settings']);
    $loader->boot();

    $builder = new ContainerBuilder();
    $builder->addDefinitions(array_merge(ContainerDefinitions::all(), [
        PluginLoader::class => $loader,
    ]));

    $container = $builder->build();

    // The kernel resolves the configuration first and reads it right away,
    // and the order is what the bug turns on: a provider that is resolved
    // while the configuration is being built closes the cycle and is dropped.
    $container->get(Config::class)->getString('SPORA_APP_ENV');

    return $container;
}

beforeEach(function (): void {
    $_ENV['SPORA_APP_URL'] = 'https://spora.example.test';
    putenv('SPORA_APP_URL=https://spora.example.test');
});

afterEach(function (): void {
    unset($_ENV['SPORA_APP_URL']);
    putenv('SPORA_APP_URL');
});

it('keeps a settings provider that depends on the configuration', function (): void {
    $registry = configDependentSettingsContainer()->get(SettingsRegistry::class);

    expect($registry->has('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBeTrue()
        ->and($registry->get('SPORA_SOCIAL_LINKEDIN_CLIENT_ID')?->group)->toBe('plugin:social');
});

it('the provider reads a configuration that is already built', function (): void {
    $registry = configDependentSettingsContainer()->get(SettingsRegistry::class);

    expect($registry->get('SPORA_SOCIAL_LINKEDIN_CLIENT_ID')?->description)
        ->toContain('https://spora.example.test');
});

it('the plugin setting reaches the resolved configuration', function (): void {
    $config = configDependentSettingsContainer()->get(Config::class);

    expect($config->get('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBe('')
        ->and($config->sourceOf('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBe(Config::SOURCE_DEFAULT);
});

it('the environment can set a plugin setting', function (): void {
    $_ENV['SPORA_SOCIAL_LINKEDIN_CLIENT_ID'] = 'from-env';
    putenv('SPORA_SOCIAL_LINKEDIN_CLIENT_ID=from-env');

    try {
        $config = configDependentSettingsContainer()->get(Config::class);

        expect($config->get('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBe('from-env')
            ->and($config->sourceOf('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBe(Config::SOURCE_ENV);
    } finally {
        unset($_ENV['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']);
        putenv('SPORA_SOCIAL_LINKEDIN_CLIENT_ID');
    }
});

/**
 * The registry is what asks the provider for its settings, so a provider
 * that reads the registry in its constructor re-enters the collection. The
 * set that comes out has to carry every plugin's settings, not the half that
 * existed when the provider looked.
 */
it('keeps the settings of a provider that reads the registry', function (): void {
    $registry = configDependentSettingsContainer()->get(SettingsRegistry::class);

    expect($registry->has('SPORA_REGISTRY_LAST_KEY'))->toBeTrue()
        ->and($registry->has('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBeTrue();
});
