<?php

declare(strict_types=1);

use Spora\Plugins\PluginLoader;
use Spora\Tools\SharedSettingsResolver;

const FIXTURE_SHARED_SETTINGS = BASE_PATH . '/tests/Fixtures/Plugins';

test('settingsClasses() returns empty when no plugin contributes one', function (): void {
    $loader = new PluginLoader([BASE_PATH . '/tests/Fixtures/plugins_with_manifest']);
    $loader->boot();

    expect($loader->settingsClasses())->toBe([]);
});

test('settingsClasses() returns the group class contributed by a fixture plugin', function (): void {
    $loader = new PluginLoader([FIXTURE_SHARED_SETTINGS]);
    $loader->boot();

    $classes = $loader->settingsClasses();

    expect($classes)->toHaveCount(1)
        ->and($classes[0])->toEndWith('\\ProviderSettings')
        ->and(class_exists($classes[0]))->toBeTrue();
});

test('the fanned-out class resolves as a usable group', function (): void {
    $loader = new PluginLoader([FIXTURE_SHARED_SETTINGS]);
    $loader->boot();

    $classes  = $loader->settingsClasses();
    $resolver = new SharedSettingsResolver($classes);

    // Referenced as strings: tests/Fixtures is excluded from static analysis,
    // so a `::class` import here would not be resolvable to it.
    $consumer = 'Tests\\Fixtures\\Plugins\\SharedSettingsPlugin\\ConsumerTool';

    expect($resolver->groupClassFor($consumer))->toBe($classes[0])
        ->and($resolver->groupClassFor(Tests\Unit\Tools\AcmeStandaloneTool::class))->toBeNull();
});

test('a warm boot from the sidecar still reports the group', function (): void {
    // The claim is that the hook needs no cache invalidation. It holds because
    // boot() re-instantiates the plugin objects from the sidecar and
    // settingsClasses() reads the live instance — nothing about the group is
    // recorded in the manifest, so a stale stamp cannot hide it.
    $stamp   = sys_get_temp_dir() . '/spora_shared_settings_' . uniqid();
    $sidecar = $stamp . '.cache.json';

    try {
        $cold = new PluginLoader([FIXTURE_SHARED_SETTINGS], $stamp);
        $cold->boot();
        expect($cold->settingsClasses())->toHaveCount(1);

        $warm = new PluginLoader([FIXTURE_SHARED_SETTINGS], $stamp);
        $warm->boot();

        expect(file_exists($sidecar))->toBeTrue()
            ->and($warm->settingsClasses())->toBe($cold->settingsClasses());
    } finally {
        @unlink($sidecar);
        @unlink($stamp);
    }
});

test('a group the plugin adds after the sidecar was written is still reported', function (): void {
    // The sharper case: the manifest is untouched, so the stamp stays current
    // and boot() takes the sidecar path rather than re-reading it. Only reading
    // the live plugin object survives this — a sidecar that recorded the group
    // list would keep reporting the stale one.
    $stamp   = sys_get_temp_dir() . '/spora_shared_settings_added_' . uniqid();
    $sidecar = $stamp . '.cache.json';

    /** @var class-string $pluginClass */
    $pluginClass = 'Tests\\Fixtures\\Plugins\\SharedSettingsPlugin\\Plugin';

    try {
        $cold = new PluginLoader([FIXTURE_SHARED_SETTINGS], $stamp);
        $cold->boot();
        expect($cold->settingsClasses())->toHaveCount(1);

        $sidecarBefore = file_get_contents($sidecar);
        expect((string) $sidecarBefore)->not->toContain('SecondarySettings');

        $pluginClass::$includeSecondaryGroup = true;

        $warm = new PluginLoader([FIXTURE_SHARED_SETTINGS], $stamp);
        $warm->boot();

        expect($warm->settingsClasses())->toHaveCount(2)
            ->and($warm->settingsClasses()[1])->toEndWith('\\SecondarySettings');
    } finally {
        $pluginClass::$includeSecondaryGroup = false;
        @unlink($sidecar);
        @unlink($stamp);
    }
});

test('a corrupt sidecar still yields the group after a full rediscovery', function (): void {
    $stamp   = sys_get_temp_dir() . '/spora_shared_settings_corrupt_' . uniqid();
    $sidecar = $stamp . '.cache.json';

    try {
        $cold = new PluginLoader([FIXTURE_SHARED_SETTINGS], $stamp);
        $cold->boot();

        file_put_contents($sidecar, '{not json');

        $warm = new PluginLoader([FIXTURE_SHARED_SETTINGS], $stamp);
        $warm->boot();

        expect($warm->settingsClasses())->toHaveCount(1);
    } finally {
        @unlink($sidecar);
        @unlink($stamp);
    }
});
