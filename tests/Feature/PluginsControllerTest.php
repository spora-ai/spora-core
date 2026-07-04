<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\PluginsController;
use Spora\Plugins\PluginLoader;
use Spora\Security\CsrfTokenService;
use Spora\Services\PluginMetadataExtractor;
use Spora\Services\PluginsService;

// Parent directory — the loader globs `dir/*/plugin.json` one level deep.
const PLUGINS_INVENTORY_FIXTURE = BASE_PATH . '/tests/Fixtures/plugins_inventory';

/**
 * @return array{0: PluginsController, 1: AuthMiddleware, 2: CsrfMiddleware}
 */
function spora_makePluginsController(?bool $installEnabled = false): array
{
    $loader = new PluginLoader([PLUGINS_INVENTORY_FIXTURE], null);
    $loader->boot();
    $service = new PluginsService($loader, new PluginMetadataExtractor());

    $authService = bootAuthLayer();
    $authMiddleware = new AuthMiddleware($authService);
    $csrfMiddleware = new CsrfMiddleware(new CsrfTokenService());

    // The read-only `index()` route doesn't need a real PluginManager. Passing
    // null keeps the existing 4 tests happy without dragging in a Symfony
    // Process factory.
    return [
        new PluginsController($service, null, $installEnabled),
        $authMiddleware,
        $csrfMiddleware,
    ];
}

describe('PluginsController', function (): void {
    it('rejects anonymous requests with 401', function (): void {
        clearSession();
        [$controller, $authMw, $csrfMw] = spora_makePluginsController();

        $request = jsonRequest('GET', '/api/v1/plugins');
        expect(fn() => callController($controller, 'index', $request, [$authMw, $csrfMw]))
            ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
    });

    it('returns the plugin inventory for an authenticated user', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('plugins@example.com', 'ValidPass1!', 'Plugins');
        simulateLoggedInSession($userId, 'plugins@example.com');

        [$controller, $authMw, $csrfMw] = spora_makePluginsController();
        $request = jsonRequest('GET', '/api/v1/plugins');
        $response = callController($controller, 'index', $request, [$authMw, $csrfMw]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);

        expect($body)->toHaveKey('data');
        expect($body['data']['plugins'])->toHaveCount(1);

        $plugin = $body['data']['plugins'][0];
        expect($plugin['slug'])->toBe('inventory-plugin');
        expect($plugin['name'])->toBe('Inventory Plugin');
        expect($plugin['description'])->toBe('A test plugin for the inventory API.');
        expect($plugin['icon'])->toBe('M12 2L2 22h20L12 2z');
        expect($plugin['version'])->toBe(1);
        expect($plugin['path'])->toEndWith('/InventoryPlugin');

        // The plugin declares ReadUrlTool — the metadata extractor pulls name + description
        // from the #[Tool] attribute via reflection (no instantiation).
        expect($plugin['bundledTools'])->toBe([
            [
                'name'        => 'read_url',
                'description' => 'Fetch and read the contents of a URL. Can parse HTML pages into Markdown, and can read XML/RSS feeds. Only http:// and https:// URLs are supported.',
            ],
        ]);

        expect($plugin['bundledDrivers'])->toBe([
            ['provider' => 'inventory_driver', 'class' => 'Tests\\Fixtures\\Plugins\\InventoryPlugin\\InventoryDriver'],
        ]);

        // Migrations status: 1 file on disk, 0 applied yet → pending_migrations.
        expect($plugin['migrations']['declared'])->toBe(1);
        expect($plugin['migrations']['filesOnDisk'])->toBe(1);
        expect($plugin['migrations']['applied'])->toBe(0);
        expect($plugin['migrations']['pending'])->toBe(1);
        expect($plugin['migrations']['status'])->toBe('pending_migrations');
        expect($plugin['migrations']['lastAppliedAt'])->toBeNull();
    });

    it('returns an empty list when no plugins are installed', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('empty-plugins@example.com', 'ValidPass1!', 'Empty');
        simulateLoggedInSession($userId, 'empty-plugins@example.com');

        $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null);
        $loader->boot();
        $service = new PluginsService($loader, new PluginMetadataExtractor());
        $controller = new PluginsController($service, null, false);

        $authMw = new AuthMiddleware($authService);
        $csrfMw = new CsrfMiddleware(new CsrfTokenService());

        $request = jsonRequest('GET', '/api/v1/plugins');
        $response = callController($controller, 'index', $request, [$authMw, $csrfMw]);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['plugins'])->toBe([]);
    });

    it('marks a fully-applied plugin as up_to_date', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('up2date@example.com', 'ValidPass1!', 'Up2Date');
        simulateLoggedInSession($userId, 'up2date@example.com');

        // Simulate Laravel recording that the migration was applied.
        Capsule::table('migrations')->insert([
            'migration' => 'inventory-plugin_000001_create_inventory_table',
            'batch'     => 1,
        ]);
        Capsule::table('schema_versions')->insert([
            'component'  => 'inventory-plugin',
            'version'    => 1,
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        [$controller, $authMw, $csrfMw] = spora_makePluginsController();
        $request = jsonRequest('GET', '/api/v1/plugins');
        $response = callController($controller, 'index', $request, [$authMw, $csrfMw]);

        $body = json_decode($response->getContent(), true);
        $m = $body['data']['plugins'][0]['migrations'];
        expect($m['applied'])->toBe(1);
        expect($m['filesOnDisk'])->toBe(1);
        expect($m['pending'])->toBe(0);
        expect($m['status'])->toBe('up_to_date');
        expect($m['lastAppliedAt'])->toBe('2026-01-01 00:00:00');
    });

    it('falls back to the bundled "puzzle" icon when the manifest omits it', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('default-icon@example.com', 'ValidPass1!', 'DefaultIcon');
        simulateLoggedInSession($userId, 'default-icon@example.com');

        $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_inventory_brain'], null);
        $loader->boot();
        $controller = new PluginsController(new PluginsService($loader, new PluginMetadataExtractor()), null, false);
        $authMw = new AuthMiddleware($authService);
        $csrfMw = new CsrfMiddleware(new CsrfTokenService());

        $request = jsonRequest('GET', '/api/v1/plugins');
        $response = callController($controller, 'index', $request, [$authMw, $csrfMw]);

        $body = json_decode($response->getContent(), true);
        expect($body['data']['plugins'][0]['icon'])->toBe('puzzle');
    });

    it('accepts a bundled icon name like "brain" in the manifest', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('bundled-icon@example.com', 'ValidPass1!', 'BundledIcon');
        simulateLoggedInSession($userId, 'bundled-icon@example.com');

        // Build a temp plugin that uses a bundled name in its manifest.
        $dir = sys_get_temp_dir() . '/spora_bundled_icon_' . uniqid();
        $slug = 'bundled-icon-plugin';
        mkdir($dir . '/' . $slug, 0o777, true);
        file_put_contents(
            $dir . '/' . $slug . '/plugin.json',
            json_encode([
                'slug'        => $slug,
                'class'       => 'Tests\\Fixtures\\Plugins\\InventoryPlugin\\Plugin',
                'description' => 'A bundled-icon test plugin.',
                'icon'        => 'brain',
            ]),
        );
        symlink(
            BASE_PATH . '/tests/Fixtures/plugins_inventory/InventoryPlugin/Plugin.php',
            $dir . '/' . $slug . '/Plugin.php',
        );

        try {
            $loader = new PluginLoader([$dir], null);
            $loader->boot();
            $controller = new PluginsController(new PluginsService($loader, new PluginMetadataExtractor()), null, false);
            $authMw = new AuthMiddleware($authService);
            $csrfMw = new CsrfMiddleware(new CsrfTokenService());

            $request = jsonRequest('GET', '/api/v1/plugins');
            $response = callController($controller, 'index', $request, [$authMw, $csrfMw]);
            $body = json_decode($response->getContent(), true);

            expect($body['data']['plugins'][0]['icon'])->toBe('brain');
        } finally {
            @unlink($dir . '/' . $slug . '/Plugin.php');
            @unlink($dir . '/' . $slug . '/plugin.json');
            @rmdir($dir . '/' . $slug);
            @rmdir($dir);
        }
    });
});
