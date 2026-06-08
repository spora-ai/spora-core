<?php

declare(strict_types=1);

use Spora\Plugins\Exceptions\PluginLoadFailedException;
use Spora\Plugins\PluginLoader;

const FIXTURE_MANIFEST_PLUGINS    = BASE_PATH . '/tests/Fixtures/plugins_with_manifest';
const FIXTURE_CUSTOM_FILE_PLUGINS = BASE_PATH . '/tests/Fixtures/plugins_with_custom_file';
const FIXTURE_INVALID_MANIFESTS   = BASE_PATH . '/tests/Fixtures/plugins_invalid_manifest';

test('boot() with non-existent directory loads zero plugins', function (): void {
    $loader = new PluginLoader('/tmp/spora_no_plugins_' . uniqid());
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(0);
});

test('boot() is idempotent — calling twice does not double-load plugins', function (): void {
    $loader = new PluginLoader(FIXTURE_MANIFEST_PLUGINS);
    $loader->boot();
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(1);
});

test('plugin is keyed by its manifest slug in getPlugins()', function (): void {
    $loader = new PluginLoader(FIXTURE_MANIFEST_PLUGINS);
    $loader->boot();

    expect($loader->getPlugins())->toHaveKey('manifest-plugin');
    expect($loader->getPlugins()['manifest-plugin']->getName())->toBe('Manifest Plugin');
});

test('drivers() returns driver map from loaded plugin', function (): void {
    $loader = new PluginLoader(FIXTURE_MANIFEST_PLUGINS);
    $loader->boot();

    expect($loader->drivers())->toHaveKey('manifest_driver');
});

test('toolClasses() returns empty array when plugin contributes no tools', function (): void {
    $loader = new PluginLoader(FIXTURE_MANIFEST_PLUGINS);
    $loader->boot();

    expect($loader->toolClasses())->toBe([]);
});

test('"file" key loads the plugin from the specified path instead of Plugin.php', function (): void {
    $loader = new PluginLoader(FIXTURE_CUSTOM_FILE_PLUGINS);
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(1);
    expect($loader->getPlugins()['named-plugin']->getName())->toBe('Named Plugin');
    expect($loader->drivers())->toHaveKey('named_driver');
});

test('manifest missing "slug" throws PluginLoadFailedException', function (): void {
    $loader = new PluginLoader(FIXTURE_INVALID_MANIFESTS . '/MissingClassKey');

    expect(fn() => $loader->boot())->toThrow(PluginLoadFailedException::class, "'slug'");
});

test('manifest with invalid slug format throws PluginLoadFailedException', function (): void {
    $loader = new PluginLoader(FIXTURE_INVALID_MANIFESTS . '/InvalidSlug');

    expect(fn() => $loader->boot())->toThrow(PluginLoadFailedException::class, 'INVALID SLUG!');
});

test('PluginLoadFailedException is a RuntimeException', function (): void {
    expect(new PluginLoadFailedException('boom'))->toBeInstanceOf(RuntimeException::class);
});

test('boot() throws PluginLoadFailedException for a manifest containing invalid JSON', function (): void {
    $dir = sys_get_temp_dir() . '/spora_bad_json_' . uniqid();
    mkdir($dir . '/broken', 0o777, true);
    file_put_contents($dir . '/broken/plugin.json', '{not json');

    $loader = new PluginLoader($dir);

    try {
        expect(fn() => $loader->boot())->toThrow(PluginLoadFailedException::class, 'invalid JSON');
    } finally {
        @unlink($dir . '/broken/plugin.json');
        @rmdir($dir . '/broken');
        @rmdir($dir);
    }
});

test('manifest whose declared class cannot be resolved is silently skipped', function (): void {
    // The manifest is structurally valid (has slug + class) but the PHP class simply
    // doesn't exist at runtime — this is a recoverable situation (e.g. bad autoload path).
    $loader = new PluginLoader(FIXTURE_INVALID_MANIFESTS . '/BadClass');
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(0);
});

test('pluginMigrationPaths() is empty for plugins with schemaVersion 0', function (): void {
    $loader = new PluginLoader(FIXTURE_MANIFEST_PLUGINS);
    $loader->boot();

    expect($loader->pluginMigrationPaths())->toBe([]);
});

test('pluginMigrationPaths() uses slug as key', function (): void {
    $loader = new PluginLoader(BASE_PATH . '/tests/Fixtures/plugins_with_migrations');
    $loader->boot();

    $paths = $loader->pluginMigrationPaths();
    expect($paths)->toHaveKey('migrating-plugin');
    expect($paths['migrating-plugin']['version'])->toBe(1);
});
