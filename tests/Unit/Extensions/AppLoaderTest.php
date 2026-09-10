<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Spora\Core\Paths;
use Spora\Extensions\AppLoader;
use Spora\Extensions\SporaExtensionInterface;

beforeEach(function (): void {
    $this->tmpDir = sys_get_temp_dir() . '/spora-app-loader-' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir, 0755, true);
    mkdir($this->tmpDir . '/app', 0755, true);
    $this->paths = new Paths($this->tmpDir);
    $this->loader = new AppLoader();
    // Unique app class name per test so PHP doesn't choke on redeclaration
    // when require_once is a no-op for already-loaded classes from a previous test.
    $this->appClass = 'App_' . bin2hex(random_bytes(4));
});

afterEach(function (): void {
    if (is_dir($this->tmpDir)) {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmpDir);
    }
});

it('returns null and is a no-op when app/App.php does not exist', function (): void {
    expect($this->loader->load($this->paths))->toBeNull();
    expect($this->loader->getApp())->toBeNull();
});

it('returns null on second load() call (idempotent)', function (): void {
    // File declares no class at all — class detection returns null.
    file_put_contents($this->tmpDir . '/app/App.php', '<?php // stub');

    $first = $this->loader->load($this->paths);
    $second = $this->loader->load($this->paths);

    expect($first)->toBeNull();
    expect($second)->toBeNull();
});

it('loads a valid App class and exposes it via getApp()', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\SpyApp {}",
    );

    $app = $this->loader->load($this->paths);

    expect($app)->toBeInstanceOf(SpyApp::class);
    expect($this->loader->getApp())->toBe($app);
});

it('throws when app/App.php exists but declares a non-SporaExtension class', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        '<?php class NotAnApp {}',
    );

    expect(fn() => $this->loader->load($this->paths))
        ->toThrow(\Spora\Extensions\Exceptions\InvalidAppClassException::class);
});

it('returns null when app/App.php exists but declares no class', function (): void {
    // Empty App.php is treated as "no App installed" — silent no-op, same
    // as the file-not-exists case above.
    file_put_contents($this->tmpDir . '/app/App.php', '<?php // no class here');

    expect($this->loader->load($this->paths))->toBeNull();
});

it('accepts an App that extends AbstractExtension without explicitly implements AppInterface', function (): void {
    // PlainApp extends AbstractExtension but does NOT implement AppInterface.
    // AppLoader must accept it because AbstractExtension implements
    // SporaExtensionInterface, which is the actual acceptance check.
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\PlainApp {}",
    );

    $app = $this->loader->load($this->paths);

    expect($app)->toBeInstanceOf(PlainApp::class);
    expect($app)->toBeInstanceOf(SporaExtensionInterface::class);
});

it('picks the concrete App over an abstract parent newly declared alongside it', function (): void {
    // The fixture reference is a string, so the only way PHP autoloads
    // AppLoaderAbstractParent (and transitively AbstractExtension) is via
    // the require_once of the synthesized app/App.php — the exact scenario
    // the bug exposes.
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Fixtures\\AppLoaderAbstractParent {}",
    );

    $app = $this->loader->load($this->paths);

    expect($app)->toBeInstanceOf($this->appClass);
    expect($app)->toBeInstanceOf(SporaExtensionInterface::class);
});

// PR-3 dropped the `registerRoutes()` and `boot()` methods from AppLoader
// (lifecycle hooks now live on PluginLoader + PSR-14 events). The test
// below is a regression guard so a future contributor cannot silently
// re-introduce them and resurrect the App+Plugin double-fire bug.
it('AppLoader exposes no registerRoutes() or boot() — lifecycle events dispatch from PluginLoader only', function (): void {
    $reflection = new ReflectionClass(AppLoader::class);
    expect($reflection->hasMethod('registerRoutes'))->toBeFalse();
    expect($reflection->hasMethod('boot'))->toBeFalse();
});
