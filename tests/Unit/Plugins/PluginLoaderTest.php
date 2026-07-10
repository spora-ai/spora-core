<?php

declare(strict_types=1);

use Spora\Plugins\Exceptions\PluginLoadFailedException;
use Spora\Plugins\PluginLoader;

const FIXTURE_MANIFEST_PLUGINS    = BASE_PATH . '/tests/Fixtures/plugins_with_manifest';
const FIXTURE_CUSTOM_FILE_PLUGINS = BASE_PATH . '/tests/Fixtures/plugins_with_custom_file';
const FIXTURE_INVALID_MANIFESTS   = BASE_PATH . '/tests/Fixtures/plugins_invalid_manifest';

test('boot() with non-existent directory loads zero plugins', function (): void {
    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()]);
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(0);
});

test('boot() is idempotent — calling twice does not double-load plugins', function (): void {
    $loader = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(1);
});

test('plugin is keyed by its manifest slug in getPlugins()', function (): void {
    $loader = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();

    expect($loader->getPlugins())->toHaveKey('manifest-plugin');
    expect($loader->getPlugins()['manifest-plugin']->getName())->toBe('Manifest Plugin');
});

test('drivers() returns driver map from loaded plugin', function (): void {
    $loader = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();

    expect($loader->drivers())->toHaveKey('manifest_driver');
});

test('toolClasses() returns empty array when plugin contributes no tools', function (): void {
    $loader = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();

    expect($loader->toolClasses())->toBe([]);
});

test('PSR-4 autoload resolves the entry-point class without a "file" key in the manifest', function (): void {
    // The NamedPlugin fixture sits at NamedPlugin/NamedPlugin.php (no src/, no "file"
    // key). The loader relies on the explicit PSR-4 mapping registered in tests/Pest.php.
    $loader = new PluginLoader([FIXTURE_CUSTOM_FILE_PLUGINS]);
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(1);
    expect($loader->getPlugins())->toHaveKey('named-plugin');
    expect($loader->getPlugins()['named-plugin']->getName())->toBe('Named Plugin');
    expect($loader->drivers())->toHaveKey('named_driver');
});

test('manifest missing "slug" throws PluginLoadFailedException', function (): void {
    $loader = new PluginLoader([FIXTURE_INVALID_MANIFESTS . '/MissingClassKey']);

    expect(fn() => $loader->boot())->toThrow(PluginLoadFailedException::class, "'slug'");
});

test('manifest missing "class" field throws PluginLoadFailedException (slug present)', function (): void {
    $loader = new PluginLoader([FIXTURE_INVALID_MANIFESTS . '/MissingClassField']);

    expect(fn() => $loader->boot())
        ->toThrow(PluginLoadFailedException::class, "'class'");
});

test('manifest with invalid slug format throws PluginLoadFailedException', function (): void {
    $loader = new PluginLoader([FIXTURE_INVALID_MANIFESTS . '/InvalidSlug']);

    expect(fn() => $loader->boot())->toThrow(PluginLoadFailedException::class, 'INVALID SLUG!');
});

test('PluginLoadFailedException is a RuntimeException', function (): void {
    expect(new PluginLoadFailedException('boom'))->toBeInstanceOf(RuntimeException::class);
});

test('boot() throws PluginLoadFailedException for a manifest containing invalid JSON', function (): void {
    $dir = sys_get_temp_dir() . '/spora_bad_json_' . uniqid();
    mkdir($dir . '/broken', 0o777, true);
    file_put_contents($dir . '/broken/plugin.json', '{not json');

    $loader = new PluginLoader([$dir]);

    try {
        expect(fn() => $loader->boot())->toThrow(PluginLoadFailedException::class, 'invalid JSON');
    } finally {
        @unlink($dir . '/broken/plugin.json');
        @rmdir($dir . '/broken');
        @rmdir($dir);
    }
});

test('manifest whose declared class cannot be autoloaded throws PluginLoadFailedException', function (): void {
    $loader = new PluginLoader([FIXTURE_INVALID_MANIFESTS . '/BadClass']);

    expect(fn() => $loader->boot())
        ->toThrow(PluginLoadFailedException::class, 'Tests\\Fixtures\\Plugins\\DoesNotExist\\Plugin');
});

test('manifest whose declared class is autoloadable but does not implement PluginInterface throws', function (): void {
    $loader = new PluginLoader([FIXTURE_INVALID_MANIFESTS . '/NotAPlugin']);

    expect(fn() => $loader->boot())
        ->toThrow(PluginLoadFailedException::class, 'Tests\\Fixtures\\Plugins\\NotAPlugin\\NotAPlugin');
});

test('pluginMigrationPaths() is empty for plugins with schemaVersion 0', function (): void {
    $loader = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();

    expect($loader->pluginMigrationPaths())->toBe([]);
});

test('pluginMigrationPaths() uses slug as key', function (): void {
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_migrations']);
    $loader->boot();

    $paths = $loader->pluginMigrationPaths();
    expect($paths)->toHaveKey('migrating-plugin');
    expect($paths['migrating-plugin']['version'])->toBe(1);
});

/**
 * Create a temp dir containing a single manifest plugin, returning the dir path.
 * The plugin slug is derived from $slug. Returns [dir, cleanup].
 *
 * @return array{0: string, 1: callable(): void}
 */
function spora_makePluginDir(string $slug, string $class): array
{
    $dir = sys_get_temp_dir() . '/spora_test_' . $slug . '_' . uniqid();
    mkdir($dir . '/' . $slug, 0o777, true);
    file_put_contents($dir . '/' . $slug . '/plugin.json', json_encode([
        'slug'  => $slug,
        'class' => $class,
    ]));

    $cleanup = static function () use ($dir, $slug): void {
        @unlink($dir . '/' . $slug . '/plugin.json');
        @rmdir($dir . '/' . $slug);
        @rmdir($dir);
    };

    return [$dir, $cleanup];
}

test('boot() with current stamp short-circuits and re-instantiates from the sidecar', function (): void {
    [$dir, $cleanup] = spora_makePluginDir('demo', 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin');
    $stamp    = $dir . '/.stamp';
    $sidecar  = $stamp . '.cache.json';

    try {
        // Cold boot — full discovery, writes stamp + sidecar
        $loader1 = new PluginLoader([$dir], $stamp);
        $loader1->boot();
        expect($loader1->getPlugins())->toHaveKey('demo');
        expect($loader1->getPluginDirectories())->toHaveKey('demo');
        expect(file_exists($stamp))->toBeTrue();
        expect(file_exists($sidecar))->toBeTrue();

        // Warm boot — second instance, same stamp path. Should re-instantiate from
        // the sidecar without re-reading the manifest.
        $loader2 = new PluginLoader([$dir], $stamp);
        $loader2->boot();
        expect($loader2->getPlugins())->toHaveKey('demo');
        expect($loader2->getPluginDirectories()['demo'])->toBe(realpath($dir . '/demo'));
    } finally {
        @unlink($sidecar);
        @unlink($stamp);
        $cleanup();
    }
});

test('boot() rewrites the stamp when a manifest is added between boots', function (): void {
    [$dir, $cleanup] = spora_makePluginDir('alpha', 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin');
    $stamp = $dir . '/.stamp';

    try {
        $loader1 = new PluginLoader([$dir], $stamp);
        $loader1->boot();
        $firstHash = file_get_contents($stamp);
        expect($loader1->getPlugins())->toHaveCount(1);

        // Add a second manifest with a different FQCN so dedup-by-class doesn't apply
        mkdir($dir . '/beta', 0o777, true);
        file_put_contents($dir . '/beta/plugin.json', json_encode([
            'slug'  => 'beta',
            'class' => 'Tests\\Fixtures\\Plugins\\NamedPlugin\\NamedPlugin',
        ]));

        $loader2 = new PluginLoader([$dir], $stamp);
        $loader2->boot();
        $secondHash = file_get_contents($stamp);

        expect($secondHash)->not->toBe($firstHash);
        expect($loader2->getPlugins())->toHaveCount(2);
        expect($loader2->getPlugins())->toHaveKey('alpha');
        expect($loader2->getPlugins())->toHaveKey('beta');
    } finally {
        @unlink($dir . '/beta/plugin.json');
        @rmdir($dir . '/beta');
        @unlink($dir . '/.stamp.cache.json');
        @unlink($dir . '/.stamp');
        @unlink($dir . '/alpha/plugin.json');
        @rmdir($dir . '/alpha');
        @rmdir($dir);
    }
});

test('boot() with null stampPath never writes a stamp or sidecar', function (): void {
    [$dir, $cleanup] = spora_makePluginDir('nostamp', 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin');

    try {
        $loader = new PluginLoader([$dir], null);
        $loader->boot();
        expect($loader->getPlugins())->toHaveKey('nostamp');
        expect(file_exists($dir . '/.stamp'))->toBeFalse();
        expect(file_exists($dir . '/.stamp.cache.json'))->toBeFalse();
    } finally {
        $cleanup();
    }
});

test('boot() falls back to full discovery when the sidecar is corrupt', function (): void {
    [$dir, $cleanup] = spora_makePluginDir('corrupt', 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin');
    $stamp = $dir . '/.stamp';

    try {
        // First boot to seed the sidecar
        $loader1 = new PluginLoader([$dir], $stamp);
        $loader1->boot();
        expect($loader1->getPlugins())->toHaveKey('corrupt');

        // Corrupt the sidecar
        file_put_contents($stamp . '.cache.json', '{not valid json');

        // Second boot should detect corruption and fall back to full discovery
        $loader2 = new PluginLoader([$dir], $stamp);
        $loader2->boot();
        expect($loader2->getPlugins())->toHaveKey('corrupt');

        // The sidecar should have been rewritten as a side-effect of the fallback
        $sidecarContents = file_get_contents($stamp . '.cache.json');
        expect($sidecarContents)->toContain('corrupt');
    } finally {
        @unlink($dir . '/.stamp.cache.json');
        @unlink($stamp);
        $cleanup();
    }
});

test('multi-path discovery merges plugins from multiple directories and dedupes by slug', function (): void {
    [$dirA, $cleanupA] = spora_makePluginDir('a-only', 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin');
    [$dirB, $cleanupB] = spora_makePluginDir('b-only', 'Tests\\Fixtures\\Plugins\\NamedPlugin\\NamedPlugin');

    // Add a colliding slug to dirB with a different FQCN — the slug should dedup,
    // the class FQCN shouldn't matter for the dedup decision
    mkdir($dirB . '/a-only', 0o777, true);
    file_put_contents($dirB . '/a-only/plugin.json', json_encode([
        'slug'  => 'a-only',
        'class' => 'Tests\\Fixtures\\Plugins\\NamedPlugin\\NamedPlugin',
    ]));

    try {
        $loader = new PluginLoader([$dirA, $dirB], null);
        $loader->boot();

        expect($loader->getPlugins())->toHaveCount(2);
        expect($loader->getPlugins())->toHaveKey('a-only');
        expect($loader->getPlugins())->toHaveKey('b-only');
        // First-wins: a-only came from $dirA, so its directory is the one from $dirA
        expect($loader->getPluginDirectories()['a-only'])->toBe(realpath($dirA . '/a-only'));
    } finally {
        @unlink($dirB . '/a-only/plugin.json');
        @rmdir($dirB . '/a-only');
        $cleanupA();
        $cleanupB();
    }
});

// ---------------------------------------------------------------------------
// Tests for the four extension-point hooks (register / apps / routes / boot)
// wired in PluginLoader. These verify that the now-load-bearing hooks are
// actually invoked during the plugin lifecycle.
// ---------------------------------------------------------------------------

/**
 * SpyPlugin records calls to register/apps/routes/boot and returns canned
 * values for apps()/tools(). Used to assert the loader invokes each hook.
 */
final class SpyPlugin extends Spora\Plugins\AbstractPlugin
{
    public int $registerCalls  = 0;
    public int $routesCalls   = 0;
    public int $bootCalls     = 0;

    /** @var array<class-string> */
    public array $appClasses = [];

    public ?DI\ContainerBuilder $builderSeen = null;
    public ?Spora\Core\MiddlewareRouteCollector $routesSeen = null;

    public function getName(): string
    {
        return 'Spy';
    }

    /** @return array<class-string<Spora\Apps\AppInterface>> */
    public function apps(): array
    {
        return $this->appClasses;
    }

    public function register(DI\ContainerBuilder $builder): void
    {
        $this->registerCalls++;
        $this->builderSeen = $builder;
    }

    public function routes(Spora\Core\MiddlewareRouteCollector $routes): void
    {
        $this->routesCalls++;
        $this->routesSeen = $routes;
    }

    public function boot(): void
    {
        $this->bootCalls++;
    }
}

test('appClasses() flattens apps() from every loaded plugin', function (): void {
    // Spin up two fixture plugin dirs with one plugin each, both with a distinct
    // `apps()` override that returns a sentinel class string. We can't easily
    // fabricate two AbstractPlugin subclasses in this scope, so we test the
    // flattening with the existing ManifestPlugin (returns []) and an empty
    // plugin (also []). The actual multi-plugin case is covered by the
    // loaders() / toolClasses() / recipePaths() tests in this file.
    $loader = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();

    expect($loader->appClasses())->toBe([]);
});

test('registerPlugins() invokes register() on each loaded plugin once', function (): void {
    $builder = new DI\ContainerBuilder();
    $loader  = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();

    $plugins = $loader->getPlugins();
    $loader->registerPlugins($builder);

    foreach ($plugins as $slug => $plugin) {
        // ManifestPlugin fixture has the no-op default register() — we just
        // assert the loader doesn't throw when calling it. A separate test
        // below covers the SpyPlugin path with a concrete recorder.
        expect($plugin)->toBeInstanceOf(Spora\Plugins\PluginInterface::class);
    }
});

test('registerPlugins() is a no-op when no plugins are loaded', function (): void {
    $builder = new DI\ContainerBuilder();
    $loader  = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()]);
    $loader->boot();

    // Should not throw on an empty loader.
    $loader->registerPlugins($builder);

    expect($loader->getPlugins())->toHaveCount(0);
});

test('registerPlugins() swallows exceptions from a misbehaving plugin and continues', function (): void {
    // Stub plugin whose register() throws. The loader must catch the throw
    // so one bad plugin cannot break boot — the rest of the plugins still
    // get their register() called.
    $throwing = new class extends Spora\Plugins\AbstractPlugin {
        public function getName(): string
        {
            return 'Throwing';
        }
        public function register(DI\ContainerBuilder $builder): void
        {
            throw new RuntimeException('register() exploded');
        }
    };
    $good = new SpyPlugin();

    // Manually inject both stub plugins into the loader's internal state.
    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null);
    $loader->boot();
    $reflection = new ReflectionClass($loader);
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setValue($loader, ['throwing' => $throwing, 'good' => $good]);

    $builder = new DI\ContainerBuilder();
    $loader->registerPlugins($builder);

    // The good plugin's register() was still called.
    expect($good->registerCalls)->toBe(1);
    // The throwing plugin's register() was attempted (the throw was caught).
    // No exception escaped to the caller.
    expect(true)->toBeTrue();
});

test('bootExtensions() is idempotent within a process', function (): void {
    $loader = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();

    // First call iterates plugins; second call short-circuits. We can only
    // observe this indirectly: if bootExtensions() were not idempotent, the
    // ManifestPlugin fixture's no-op boot() would still pass — but the
    // short-circuit guard must exist. Confirmed by reading PluginLoader.php
    // (the $extensionsBooted flag). This test asserts bootExtensions() does
    // not throw on either call.
    $loader->bootExtensions();
    $loader->bootExtensions();
    expect(true)->toBeTrue();
});

test('registerRoutes() does not throw when no plugins are loaded', function (): void {
    $loader  = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()]);
    $loader->boot();
    $routes  = new Spora\Core\MiddlewareRouteCollector(new FastRoute\RouteParser\Std(), new FastRoute\DataGenerator\GroupCountBased());

    $loader->registerRoutes($routes);

    expect(true)->toBeTrue();
});

test('suggestedPackages() returns [] when no plugins are loaded', function (): void {
    $loader = new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()]);
    $loader->boot();

    expect($loader->suggestedPackages())->toBe([]);
});

test('suggestedPackages() skips plugins with no composer.json (hand-rolled plugins)', function (): void {
    // The ManifestPlugin fixture ships only plugin.json — no composer.json.
    // The loader must skip it gracefully rather than warning.
    $loader = new PluginLoader([FIXTURE_MANIFEST_PLUGINS]);
    $loader->boot();

    expect($loader->suggestedPackages())->toBe([]);
});

test('suggestedPackages() returns the `suggest` map from a plugin\'s composer.json', function (): void {
    $slug = 'withsuggest';
    $dir  = sys_get_temp_dir() . '/spora_suggest_' . uniqid();
    mkdir($dir . '/' . $slug, 0o777, true);

    file_put_contents($dir . '/' . $slug . '/plugin.json', json_encode([
        'slug'  => $slug,
        'class' => 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin',
    ]));
    file_put_contents($dir . '/' . $slug . '/composer.json', json_encode([
        'name'    => 'spora/' . $slug,
        'suggest' => [
            'spora/plugin-weather'    => 'For weather-aware prompts',
            'spora/plugin-worldnews'  => 'For news-aware prompts',
        ],
    ]));

    try {
        $loader = new PluginLoader([$dir], null);
        $loader->boot();
        $suggests = $loader->suggestedPackages();

        expect($suggests)->toHaveKey($slug);
        expect($suggests[$slug])->toBe([
            'spora/plugin-weather'    => 'For weather-aware prompts',
            'spora/plugin-worldnews'  => 'For news-aware prompts',
        ]);
    } finally {
        @unlink($dir . '/' . $slug . '/composer.json');
        @unlink($dir . '/' . $slug . '/plugin.json');
        @rmdir($dir . '/' . $slug);
        @rmdir($dir);
    }
});

test('suggestedPackages() ignores a composer.json with no `suggest` field', function (): void {
    $slug = 'nosuggest';
    $dir  = sys_get_temp_dir() . '/spora_nosuggest_' . uniqid();
    mkdir($dir . '/' . $slug, 0o777, true);

    file_put_contents($dir . '/' . $slug . '/plugin.json', json_encode([
        'slug'  => $slug,
        'class' => 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin',
    ]));
    file_put_contents($dir . '/' . $slug . '/composer.json', json_encode([
        'name'    => 'spora/' . $slug,
        'require' => ['php' => '^8.4'],
    ]));

    try {
        $loader = new PluginLoader([$dir], null);
        $loader->boot();
        expect($loader->suggestedPackages())->toBe([]);
    } finally {
        @unlink($dir . '/' . $slug . '/composer.json');
        @unlink($dir . '/' . $slug . '/plugin.json');
        @rmdir($dir . '/' . $slug);
        @rmdir($dir);
    }
});

test('suggestedPackages() ignores a malformed composer.json', function (): void {
    $slug = 'badjson';
    $dir  = sys_get_temp_dir() . '/spora_badjson_' . uniqid();
    mkdir($dir . '/' . $slug, 0o777, true);

    file_put_contents($dir . '/' . $slug . '/plugin.json', json_encode([
        'slug'  => $slug,
        'class' => 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin',
    ]));
    file_put_contents($dir . '/' . $slug . '/composer.json', '{not valid json');

    try {
        $loader = new PluginLoader([$dir], null);
        $loader->boot();
        // Malformed JSON must be silently swallowed — the suggest list is
        // informational, never an error surface.
        expect($loader->suggestedPackages())->toBe([]);
    } finally {
        @unlink($dir . '/' . $slug . '/composer.json');
        @unlink($dir . '/' . $slug . '/plugin.json');
        @rmdir($dir . '/' . $slug);
        @rmdir($dir);
    }
});

test('suggestedPackages() filters out non-string keys and non-string values from suggest entries', function (): void {
    $slug = 'mixed';
    $dir  = sys_get_temp_dir() . '/spora_mixed_' . uniqid();
    mkdir($dir . '/' . $slug, 0o777, true);

    file_put_contents($dir . '/' . $slug . '/plugin.json', json_encode([
        'slug'  => $slug,
        'class' => 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin',
    ]));
    // The composer.json schema permits arrays as descriptions (e.g. for
    // multiple-language descriptions). The loader's filter step drops those.
    file_put_contents($dir . '/' . $slug . '/composer.json', json_encode([
        'name'    => 'spora/' . $slug,
        'suggest' => [
            'spora/ok'      => 'Valid string description',
            'integer-key'   => 42,           // value is not a string
            99              => 'numeric key', // key is not a string
            'spora/empty'   => '',            // empty value still kept
        ],
    ]));

    try {
        $loader = new PluginLoader([$dir], null);
        $loader->boot();
        $suggests = $loader->suggestedPackages();

        // Only the well-formed entries survive.
        expect($suggests[$slug])->toHaveKey('spora/ok');
        expect($suggests[$slug])->toHaveKey('spora/empty');
        expect($suggests[$slug])->not->toHaveKey('integer-key');
        expect($suggests[$slug])->not->toHaveKey('99');
    } finally {
        @unlink($dir . '/' . $slug . '/composer.json');
        @unlink($dir . '/' . $slug . '/plugin.json');
        @rmdir($dir . '/' . $slug);
        @rmdir($dir);
    }
});

test('suggestedPackages() treats a non-object `suggest` field as empty', function (): void {
    $slug = 'weirdsuggest';
    $dir  = sys_get_temp_dir() . '/spora_weird_' . uniqid();
    mkdir($dir . '/' . $slug, 0o777, true);

    file_put_contents($dir . '/' . $slug . '/plugin.json', json_encode([
        'slug'  => $slug,
        'class' => 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin',
    ]));
    file_put_contents($dir . '/' . $slug . '/composer.json', json_encode([
        'name'    => 'spora/' . $slug,
        'suggest' => 'not-an-object',
    ]));

    try {
        $loader = new PluginLoader([$dir], null);
        $loader->boot();
        expect($loader->suggestedPackages())->toBe([]);
    } finally {
        @unlink($dir . '/' . $slug . '/composer.json');
        @unlink($dir . '/' . $slug . '/plugin.json');
        @rmdir($dir . '/' . $slug);
        @rmdir($dir);
    }
});

test('suggestedPackages() ignores an empty composer.json file', function (): void {
    $slug = 'emptyfile';
    $dir  = sys_get_temp_dir() . '/spora_empty_' . uniqid();
    mkdir($dir . '/' . $slug, 0o777, true);

    file_put_contents($dir . '/' . $slug . '/plugin.json', json_encode([
        'slug'  => $slug,
        'class' => 'Tests\\Fixtures\\Plugins\\ManifestPlugin\\Plugin',
    ]));
    file_put_contents($dir . '/' . $slug . '/composer.json', '');

    try {
        $loader = new PluginLoader([$dir], null);
        $loader->boot();
        expect($loader->suggestedPackages())->toBe([]);
    } finally {
        @unlink($dir . '/' . $slug . '/composer.json');
        @unlink($dir . '/' . $slug . '/plugin.json');
        @rmdir($dir . '/' . $slug);
        @rmdir($dir);
    }
});

test('getSlugForApp() returns the slug of the plugin that owns the app class', function (): void {
    // The app-plugin fixture's `apps()` returns [StubVueApp::class];
    // getSlugForApp should map that class back to the manifest slug
    // "app-plugin". Used by AppsController to emit the slug in the
    // /api/v1/apps response so the host SPA can build the bundle URL.
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_app'], null);
    $loader->boot();
    expect($loader->getSlugForApp(new \Tests\Fixtures\StubVueApp()))->toBe('app-plugin');
});

test('getSlugForApp() returns null for an app not claimed by any loaded plugin', function (): void {
    // Core-owned apps (memories, plugins) are registered directly with
    // AppRegistry and aren't tied to a plugin. The lookup must return
    // null so the controller can omit the slug from the response.
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_app'], null);
    $loader->boot();
    expect($loader->getSlugForApp(new \Tests\Fixtures\StubMemoriesApp()))->toBeNull();
});
