<?php

declare(strict_types=1);

use Spora\Plugins\PluginLoader;

const FIXTURE_PLUGINS = BASE_PATH . '/tests/Fixtures/plugins';

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('boot() discovers a Plugin.php implementing PluginInterface', function (): void {
    $loader = new PluginLoader(FIXTURE_PLUGINS);
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(1);
    expect($loader->getPlugins()[0]->getName())->toBe('Test Plugin');
});

test('boot() is idempotent — calling twice does not double-load plugins', function (): void {
    $loader = new PluginLoader(FIXTURE_PLUGINS);
    $loader->boot();
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(1);
});

test('boot() with non-existent directory loads zero plugins', function (): void {
    $loader = new PluginLoader('/tmp/spora_no_plugins_' . uniqid());
    $loader->boot();

    expect($loader->getPlugins())->toHaveCount(0);
});

test('drivers() returns driver map from loaded plugins', function (): void {
    $loader = new PluginLoader(FIXTURE_PLUGINS);
    $loader->boot();

    expect($loader->drivers())->toHaveKey('test_driver');
});

test('recipePaths() returns paths contributed by loaded plugins', function (): void {
    $loader = new PluginLoader(FIXTURE_PLUGINS);
    $loader->boot();

    expect($loader->recipePaths())->not()->toBeEmpty();
    expect($loader->recipePaths()[0])->toContain('plugin_recipes');
});

test('toolClasses() returns empty array when plugin contributes no tools', function (): void {
    $loader = new PluginLoader(FIXTURE_PLUGINS);
    $loader->boot();

    expect($loader->toolClasses())->toBe([]);
});
