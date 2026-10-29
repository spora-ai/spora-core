<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Settings\PluginSettingsProviderInterface;
use Spora\Settings\PluginSettingsRegistry;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;

/**
 * A provider for a plugin with the slug `social`, giving its settings
 * without a prefix — the registry builds the full key.
 */
function socialSettingsProvider(): PluginSettingsProviderInterface
{
    return new class implements PluginSettingsProviderInterface {
        public function slug(): string
        {
            return 'social';
        }

        /**
         * @return list<SettingDefinition>
         */
        public function settings(): array
        {
            return [
                new SettingDefinition(
                    key: 'linkedin_client_id',
                    label: 'LinkedIn client ID',
                    type: SettingDefinition::TYPE_TEXT,
                    default: '',
                    description: 'The client id of the LinkedIn app.',
                ),
                new SettingDefinition(
                    key: 'linkedin_client_secret',
                    label: 'LinkedIn client secret',
                    type: SettingDefinition::TYPE_PASSWORD,
                    default: '',
                    secret: true,
                ),
            ];
        }
    };
}

/**
 * @param list<PluginSettingsProviderInterface> $providers
 */
function pluginSettingsRegistryFor(array $providers): PluginSettingsRegistry
{
    $byClass = [];
    foreach ($providers as $index => $provider) {
        $byClass['provider-' . $index] = $provider;
    }

    return new PluginSettingsRegistry(
        array_keys($byClass),
        static fn(string $class): PluginSettingsProviderInterface => $byClass[$class],
    );
}

/**
 * A provider for a plugin with the slug `zernio`.
 */
function zernioSettingsProvider(): PluginSettingsProviderInterface
{
    return new class implements PluginSettingsProviderInterface {
        public function slug(): string
        {
            return 'zernio';
        }

        /**
         * @return list<SettingDefinition>
         */
        public function settings(): array
        {
            return [
                new SettingDefinition(
                    key: 'workspace',
                    label: 'Workspace',
                    type: SettingDefinition::TYPE_TEXT,
                ),
            ];
        }
    };
}

it('merges the definitions every provider contributes', function (): void {
    $registry = pluginSettingsRegistryFor([
        socialSettingsProvider(),
        zernioSettingsProvider(),
    ]);

    expect(array_keys($registry->definitions()))
        ->toBe([
            'SPORA_SOCIAL_LINKEDIN_CLIENT_ID',
            'SPORA_SOCIAL_LINKEDIN_CLIENT_SECRET',
            'SPORA_ZERNIO_WORKSPACE',
        ]);
});

it('rewrites the key to SPORA_<SLUG>_<KEY>', function (): void {
    $definitions = pluginSettingsRegistryFor([socialSettingsProvider()])->definitions();

    expect(array_keys($definitions))->toBe([
        'SPORA_SOCIAL_LINKEDIN_CLIENT_ID',
        'SPORA_SOCIAL_LINKEDIN_CLIENT_SECRET',
    ]);
});

it('rewrites the storage group to plugin:<slug>', function (): void {
    $definitions = pluginSettingsRegistryFor([socialSettingsProvider()])->definitions();

    expect($definitions['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']->group)->toBe('plugin:social');
});

it('puts the setting on the plugins section', function (): void {
    $definitions = pluginSettingsRegistryFor([socialSettingsProvider()])->definitions();

    expect($definitions['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']->section)->toBe('plugins');
});

it('keeps everything else the provider declared', function (): void {
    $definitions = pluginSettingsRegistryFor([socialSettingsProvider()])->definitions();
    $secret = $definitions['SPORA_SOCIAL_LINKEDIN_CLIENT_SECRET'];

    expect($definitions['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']->label)->toBe('LinkedIn client ID')
        ->and($definitions['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']->type)->toBe(SettingDefinition::TYPE_TEXT)
        ->and($definitions['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']->description)->toBe('The client id of the LinkedIn app.')
        ->and($secret->secret)->toBeTrue();
});

it('does not double a prefix the provider spelled out itself', function (): void {
    $registry = new PluginSettingsRegistry(
        ['a'],
        static fn(string $class): PluginSettingsProviderInterface => new class implements PluginSettingsProviderInterface {
            public function slug(): string
            {
                return 'social';
            }

            /**
             * @return list<SettingDefinition>
             */
            public function settings(): array
            {
                return [new SettingDefinition(
                    key: 'SPORA_SOCIAL_LINKEDIN_CLIENT_ID',
                    label: 'LinkedIn client ID',
                    type: SettingDefinition::TYPE_TEXT,
                )];
            }
        },
    );

    expect(array_keys($registry->definitions()))->toBe(['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']);
});

it('a provider that throws does not take the others with it', function (): void {
    $registry = new PluginSettingsRegistry(
        ['broken', 'working'],
        static function (string $class): PluginSettingsProviderInterface {
            if ($class === 'broken') {
                throw new RuntimeException('cannot be resolved');
            }

            return socialSettingsProvider();
        },
    );

    expect(array_keys($registry->definitions()))->toBe([
        'SPORA_SOCIAL_LINKEDIN_CLIENT_ID',
        'SPORA_SOCIAL_LINKEDIN_CLIENT_SECRET',
    ]);
});

it('the settings registry reads the plugin definitions as its own', function (): void {
    $registry = new SettingsRegistry(
        new Paths(BASE_PATH),
        pluginSettingsRegistryFor([socialSettingsProvider()]),
    );

    expect($registry->has('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBeTrue()
        ->and($registry->get('SPORA_SOCIAL_LINKEDIN_CLIENT_ID')?->group)->toBe('plugin:social')
        // The core declarations are still there — the plugin adds to them.
        ->and($registry->has('SPORA_ALLOW_REGISTRATION'))->toBeTrue();
});

it('a plugin setting is never boot-only', function (): void {
    $registry = new SettingsRegistry(
        new Paths(BASE_PATH),
        pluginSettingsRegistryFor([socialSettingsProvider()]),
    );

    expect($registry->bootOnlyKeys())->not->toContain('SPORA_SOCIAL_LINKEDIN_CLIENT_ID');
});

/**
 * A provider whose slug and key spell a core setting.
 *
 * The slug `db` and the key `driver` give `SPORA_DB_DRIVER` — a setting the
 * host owns, and one that is read before the database opens.
 */
function coreKeyHijackingProvider(): PluginSettingsProviderInterface
{
    return new class implements PluginSettingsProviderInterface {
        public function slug(): string
        {
            return 'db';
        }

        /**
         * @return list<SettingDefinition>
         */
        public function settings(): array
        {
            return [
                new SettingDefinition(
                    key: 'driver',
                    label: 'Hijacked driver',
                    type: SettingDefinition::TYPE_SELECT,
                    default: 'hijacked',
                ),
            ];
        }
    };
}

/**
 * @param list<string> $reservedKeys
 */
function registryWithHijackingProvider(array $reservedKeys): SettingsRegistry
{
    $provider = coreKeyHijackingProvider();

    return new SettingsRegistry(
        new Paths(BASE_PATH),
        new PluginSettingsRegistry(
            ['hijacker'],
            static fn(string $class): PluginSettingsProviderInterface => $provider,
            static fn(): array => $reservedKeys,
        ),
    );
}

it('refuses a plugin setting that spells a core setting', function (): void {
    $core = new SettingsRegistry(new Paths(BASE_PATH));
    $registry = registryWithHijackingProvider(array_keys($core->coreDefinitions()));

    expect($registry->get('SPORA_DB_DRIVER')?->group)->toBe('framework')
        ->and($registry->get('SPORA_DB_DRIVER')?->bootOnly)->toBeTrue()
        ->and($registry->get('SPORA_DB_DRIVER')?->default)->toBe('sqlite')
        ->and($registry->get('SPORA_DB_DRIVER')?->label)->not->toBe('Hijacked driver');
});

it('the core setting wins even when the plugin registry does not guard', function (): void {
    $registry = registryWithHijackingProvider([]);

    expect($registry->get('SPORA_DB_DRIVER')?->group)->toBe('framework')
        ->and($registry->get('SPORA_DB_DRIVER')?->bootOnly)->toBeTrue()
        ->and($registry->get('SPORA_DB_DRIVER')?->default)->toBe('sqlite');
});

it('reports a refused plugin setting to the operator', function (): void {
    $core = new SettingsRegistry(new Paths(BASE_PATH));
    $registry = registryWithHijackingProvider(array_keys($core->coreDefinitions()));

    $problems = $registry->pluginProblems();

    expect($problems)->toHaveCount(1)
        ->and($problems[0]['message'])->toContain('SPORA_DB_DRIVER');
});

it('the loader collects the plugin setting provider classes', function (): void {
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_plugin_settings']);
    $loader->boot();

    expect($loader->providerClasses()->pluginSettings())
        ->toBe(['Tests\Fixtures\Plugins\PluginSettingsPlugin\StubPluginSettingsProvider']);
});
