<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Http\GlobalSettingsController;
use Spora\Settings\PluginSettingsProviderInterface;
use Spora\Settings\PluginSettingsRegistry;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A plugin-contributed setting has to be a real setting, not a row nobody
 * reads: the admin API has to accept the key, the store has to keep it under
 * the plugin's own group, and the resolved configuration has to carry the
 * value the operator typed.
 */
function pluginSettingsRegistry(): SettingsRegistry
{
    $provider = new class implements PluginSettingsProviderInterface {
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
                ),
            ];
        }
    };

    return new SettingsRegistry(
        new Paths(BASE_PATH),
        new PluginSettingsRegistry(
            ['social'],
            static fn(string $class): PluginSettingsProviderInterface => $provider,
        ),
    );
}

function pluginSettingsController(SettingsRegistry $registry): GlobalSettingsController
{
    return new GlobalSettingsController(
        $registry,
        new SettingsRepository(
            new Spora\Core\SecurityManager(str_repeat('p', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            $registry,
        ),
        new Config(),
        new AuthService(
            new Delight\Auth\Auth(new PDO('sqlite::memory:')),
            'https://host.test',
            '',
        ),
    );
}

/**
 * @param array<string, mixed> $body
 */
function pluginSettingsPatch(GlobalSettingsController $controller, array $body): JsonResponse
{
    return $controller->update(new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode($body, JSON_THROW_ON_ERROR),
    ));
}

test('the admin API accepts a plugin-contributed key', function (): void {
    $registry = pluginSettingsRegistry();

    $response = pluginSettingsPatch(pluginSettingsController($registry), [
        'settings' => [['key' => 'SPORA_SOCIAL_LINKEDIN_CLIENT_ID', 'value' => 'abc123']],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json_decode((string) $response->getContent(), true)['data']['updated'])
        ->toBe(['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']);
});

test('the store keeps a plugin-contributed key under the plugin group', function (): void {
    $registry = pluginSettingsRegistry();

    pluginSettingsPatch(pluginSettingsController($registry), [
        'settings' => [['key' => 'SPORA_SOCIAL_LINKEDIN_CLIENT_ID', 'value' => 'abc123']],
    ]);

    $row = Illuminate\Database\Capsule\Manager::table('settings')
        ->where('setting_key', 'SPORA_SOCIAL_LINKEDIN_CLIENT_ID')
        ->first();

    expect($row)->not->toBeNull()
        ->and((string) $row->group_key)->toBe('plugin:social')
        ->and((string) $row->value)->toBe('abc123');
});

test('the store resolves a plugin-contributed key', function (): void {
    $registry = pluginSettingsRegistry();

    pluginSettingsPatch(pluginSettingsController($registry), [
        'settings' => [['key' => 'SPORA_SOCIAL_LINKEDIN_CLIENT_ID', 'value' => 'abc123']],
    ]);

    $rows = (new SettingsRepository(
        new Spora\Core\SecurityManager(str_repeat('p', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        $registry,
    ))->allForGroup('plugin:social');

    expect($rows)->toHaveKey('SPORA_SOCIAL_LINKEDIN_CLIENT_ID')
        ->and($rows['SPORA_SOCIAL_LINKEDIN_CLIENT_ID']->value)->toBe('abc123');
});

test('the resolved configuration carries the stored value', function (): void {
    $registry = pluginSettingsRegistry();

    pluginSettingsPatch(pluginSettingsController($registry), [
        'settings' => [['key' => 'SPORA_SOCIAL_LINKEDIN_CLIENT_ID', 'value' => 'abc123']],
    ]);

    $config = new Config();
    $config->applySettingsOverlay(
        new SettingsRepository(
            new Spora\Core\SecurityManager(str_repeat('p', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            $registry,
        ),
        $registry,
    );

    expect($config->get('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBe('abc123')
        ->and($config->sourceOf('SPORA_SOCIAL_LINKEDIN_CLIENT_ID'))->toBe(Config::SOURCE_DATABASE);
});

test('the admin API lists the plugin setting on the plugins section', function (): void {
    $registry = pluginSettingsRegistry();

    $response = pluginSettingsController($registry)->index();
    $sections = json_decode((string) $response->getContent(), true)['data']['sections'];

    $plugins = null;
    foreach ($sections as $section) {
        if ($section['key'] === 'plugins') {
            $plugins = $section;
        }
    }

    expect($plugins)->not->toBeNull();

    $keys = array_column($plugins['settings'], 'key');
    expect($keys)->toContain('SPORA_SOCIAL_LINKEDIN_CLIENT_ID');
});
