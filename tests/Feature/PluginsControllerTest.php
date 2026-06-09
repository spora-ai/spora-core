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

const PLUGINS_INVENTORY_FIXTURE = BASE_PATH . '/tests/Fixtures/plugins_inventory';

/**
 * Build the controller + middleware stack against the in-tree fixture plugin.
 *
 * @return array{0: PluginsController, 1: AuthMiddleware, 2: CsrfMiddleware}
 */
function spora_makePluginsController(): array
{
    ensureMigrationTables();

    $loader = new PluginLoader([PLUGINS_INVENTORY_FIXTURE], null);
    $loader->boot();
    $service = new PluginsService($loader, new PluginMetadataExtractor());

    $authService = bootAuthLayer();
    $authMiddleware = new AuthMiddleware($authService);
    $csrfMiddleware = new CsrfMiddleware(new CsrfTokenService());

    return [new PluginsController($service), $authMiddleware, $csrfMiddleware];
}

/**
 * Create the two tables PluginsService reads from. The Pest beforeEach boots an
 * in-memory SQLite connection but doesn't run the schema installer, so we create
 * them here with the minimal columns our queries touch.
 */
function ensureMigrationTables(): void
{
    $schema = Capsule::schema();

    if (!$schema->hasTable('migrations')) {
        $schema->create('migrations', static function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
    }

    if (!$schema->hasTable('schema_versions')) {
        $schema->create('schema_versions', static function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('component')->primary();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }
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
        $controller = new PluginsController($service);

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
});
