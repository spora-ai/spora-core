<?php

declare(strict_types=1);

use Spora\Plugins\PluginLoader;

const FIXTURE_MANIFEST_PLUGINS    = BASE_PATH . '/tests/Fixtures/plugins_with_manifest';
const FIXTURE_CUSTOM_FILE_PLUGINS = BASE_PATH . '/tests/Fixtures/plugins_with_custom_file';
const FIXTURE_INVALID_MANIFESTS   = BASE_PATH . '/tests/Fixtures/plugins_invalid_manifest';

// ---------------------------------------------------------------------------
// Basics
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// plugin.json — conventional Plugin.php file
// ---------------------------------------------------------------------------

test('manifest with no "file" key loads class from Plugin.php', function (): void {
    $loader = new PluginLoader(FIXTURE_MANIFEST_PLUGINS);
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(1);
    expect($loader->getPlugins()[0]->getName())->toBe('Manifest Plugin');
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

// ---------------------------------------------------------------------------
// plugin.json — explicit "file" key
// ---------------------------------------------------------------------------

test('"file" key loads the plugin from the specified path instead of Plugin.php', function (): void {
    // The fixture has plugin.json pointing to src/NamedPlugin.php — no Plugin.php exists.
    // If the loader ignored "file" and fell back to Plugin.php, it would load zero plugins.
    $loader = new PluginLoader(FIXTURE_CUSTOM_FILE_PLUGINS);
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(1);
    expect($loader->getPlugins()[0]->getName())->toBe('Named Plugin');
    expect($loader->drivers())->toHaveKey('named_driver');
});

// ---------------------------------------------------------------------------
// Invalid / broken manifests — silent skip, no exception
// ---------------------------------------------------------------------------

test('manifest missing "class" key is silently skipped', function (): void {
    $loader = new PluginLoader(FIXTURE_INVALID_MANIFESTS . '/MissingClassKey');
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(0);
});

test('manifest whose "class" does not exist after require is silently skipped', function (): void {
    $loader = new PluginLoader(FIXTURE_INVALID_MANIFESTS . '/NonExistentClass');
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(0);
});

test('directory of only invalid manifests loads zero plugins without throwing', function (): void {
    $loader = new PluginLoader(FIXTURE_INVALID_MANIFESTS);
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(0);
});
