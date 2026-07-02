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
