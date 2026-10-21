<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Psr\Log\NullLogger;
use Spora\Apps\AppRegistry;
use Spora\Apps\PluginsApp;
use Spora\Core\Config;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\AppsController;
use Spora\Http\GlobalSettingsController;
use Spora\Http\ToolController;
use Spora\I18n\CatalogLoader;
use Spora\I18n\Translator;
use Spora\Plugins\PluginLoader;
use Spora\Services\PluginMetadataExtractor;
use Spora\Services\PluginsService;
use Spora\Services\ToolConfigService;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Spora\Tools\CalculatorTool;
use Spora\Tools\MediaTool;
use Spora\Tools\TimeTool;

/**
 * The localization choke points.
 *
 * Each of these is one serialization point, so the test is one assertion per
 * surface: with a German catalogue the operator-facing string changes, and
 * the model-facing string sitting next to it does not. The second half is
 * the load-bearing one — a German tool description would degrade dispatch on
 * a model that will not reason about language.
 */
function germanTranslator(): Translator
{
    $translator = new Translator(new CatalogLoader());
    $translator->useLocale('de');

    return $translator;
}

/**
 * A copy of the inventory fixture plugin whose `plugin.json#description`
 * is already in the shipped German catalogue, so the assertion is about
 * the lookup and not about a translation someone might edit. Copied rather
 * than patched in place — the fixture is shared with the parallel runner.
 */
function localePluginFixture(): string
{
    $root = sys_get_temp_dir() . '/spora-i18n-plugin-fixture';
    $copy = $root . '/InventoryPlugin';
    if (!is_dir($copy)) {
        mkdir($copy, 0o777, true);
    }

    file_put_contents($copy . '/composer.json', (string) file_get_contents(
        BASE_PATH . '/tests/Fixtures/plugins_inventory/InventoryPlugin/composer.json',
    ));
    copy(BASE_PATH . '/tests/Fixtures/plugins_inventory/InventoryPlugin/Plugin.php', $copy . '/Plugin.php');
    file_put_contents($copy . '/plugin.json', (string) json_encode([
        'slug' => 'inventory-plugin',
        'class' => 'Tests\\Fixtures\\Plugins\\InventoryPlugin\\Plugin',
        'description' => 'Installed plugins and their status.',
    ]));

    return $root;
}

function removeLocalePluginFixture(): void
{
    $copy = sys_get_temp_dir() . '/spora-i18n-plugin-fixture/InventoryPlugin';
    foreach ((array) glob($copy . '/*') as $file) {
        unlink((string) $file);
    }

    if (is_dir($copy) && rmdir($copy)) {
        rmdir(dirname($copy));
    }
}

it('translates a tool display name and category but not its LLM description or operations', function (): void {
    $controller = new ToolController(
        bootAuthLayer(),
        new ToolConfigService(new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), [CalculatorTool::class]),
        [CalculatorTool::class],
        null,
        null,
        null,
        germanTranslator(),
    );

    $body = json_decode((string) $controller->index()->getContent(), true);
    $tool = $body['data']['tools'][0];

    $english = json_decode((string) (new ToolController(
        bootAuthLayer(),
        new ToolConfigService(new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), [CalculatorTool::class]),
        [CalculatorTool::class],
    ))->index()->getContent(), true)['data']['tools'][0];

    expect($tool['display_name'])->toBe('Taschenrechner')
        ->and($tool['category'])->toBe('Produktivität')
        ->and($tool['description'])->toBe($english['description'])
        ->and($tool['operations'])->toEqual($english['operations']);
});

it('leaves a ToolSetting label and its option labels in English and translates its description', function (): void {
    $controller = new ToolController(
        bootAuthLayer(),
        new ToolConfigService(new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), [MediaTool::class]),
        [MediaTool::class],
        null,
        null,
        null,
        germanTranslator(),
    );

    $body = json_decode((string) $controller->index()->getContent(), true);
    $schema = $body['data']['tools'][0]['settings_schema'];
    $scope = array_column($schema, null, 'key')['scope'];

    expect($scope['label'])->toBe('Library scope')
        ->and($scope['options'])->toBe(['agent' => 'Only media created by this agent', 'principal' => 'All media owned by the calling agent\'s principal (direct uploads + every agent of the principal)'])
        ->and($scope['description'])->toBe('Legt fest, welche Zeilen in media_assets das Tool lesen darf.');
});

it('translates an app display name and description', function (): void {
    $registry = new AppRegistry();
    $registry->register(PluginsApp::class);

    $body = json_decode(
        (string) (new AppsController($registry, null, germanTranslator()))->index()->getContent(),
        true,
    );

    expect($body['data']['apps'][0]['displayName'])->toBe('Plugins')
        ->and($body['data']['apps'][0]['description'])->toBe('Installierte Plugins und ihr Status.');
});

it('translates a declared setting label, description and option label', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH, BASE_PATH));

    $body = json_decode((string) (new GlobalSettingsController(
        $registry,
        new SettingsRepository(new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), $registry),
        new Config([]),
        bootAuthLayer(),
        germanTranslator(),
    ))->index()->getContent(), true);

    $sections = array_column($body['data']['sections'], null, 'key');
    $general = array_column($sections['general']['settings'], null, 'key');

    expect($sections['general']['label'])->toBe('Allgemein')
        ->and($general['SPORA_APP_NAME']['label'])->toBe('Anwendungsname')
        ->and($general['SPORA_APP_NAME']['description'])->toBe('Wird im Browser-Tab und in ausgehenden E-Mails angezeigt.')
        ->and($general['SPORA_APP_ENV']['options'])
            ->toBe([['value' => 'production', 'label' => 'Produktion'], ['value' => 'development', 'label' => 'Entwicklung'], ['value' => 'local', 'label' => 'Lokal']]);
});

it('renders English when no catalogue has the message', function (): void {
    // Nothing to translate is not a failure: an unharvested string, or a
    // locale nobody has translated, both render the source.
    $controller = new ToolController(
        bootAuthLayer(),
        new ToolConfigService(new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), new NullLogger(), [TimeTool::class]),
        [TimeTool::class],
    );

    $body = json_decode((string) $controller->index()->getContent(), true);

    expect($body['data']['tools'][0]['display_name'])->toBe('Time');
});

it('translates a plugin manifest description at render time', function (): void {
    $loader = new PluginLoader([localePluginFixture()], null);
    $loader->boot();

    $service = new PluginsService($loader, new PluginMetadataExtractor(), germanTranslator());
    $plugin = $service->listPlugins()[0];

    // The manifest is a file on disk that nobody translated; the catalogue
    // is what carries the German wording, keyed by what the file says.
    expect($plugin['description'])->toBe('Installierte Plugins und ihr Status.');

    removeLocalePluginFixture();
});
