<?php

declare(strict_types=1);

use Spora\Apps\PluginsApp;

test('exposes the plugins app metadata', function (): void {
    $app = new PluginsApp();

    expect($app->name())->toBe('plugins');
    expect($app->displayName())->toBe('Plugins');
    expect($app->description())->toBeString();
    expect($app->description())->not->toBe('');
    expect($app->icon())->toBe('puzzle');
});

test('is registered through the AppRegistry under the "plugins" slug', function (): void {
    $registry = new Spora\Apps\AppRegistry();
    $registry->register(PluginsApp::class);

    $all = $registry->all();
    expect($all)->toHaveKey('plugins');
    expect($all['plugins'])->toBeInstanceOf(PluginsApp::class);
});
