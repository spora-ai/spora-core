<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use Spora\Core\Paths;
use Spora\Extensions\AbstractExtension;
use Spora\Extensions\AppLoader;
use Spora\Extensions\SporaExtensionInterface;
use Throwable;

beforeEach(function (): void {
    $this->tmpDir = sys_get_temp_dir() . '/spora-app-loader-' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir, 0755, true);
    mkdir($this->tmpDir . '/app', 0755, true);
    $this->paths = new Paths($this->tmpDir);
    $this->builder = new \DI\ContainerBuilder();
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
    expect($this->loader->load($this->paths, $this->builder))->toBeNull();
    expect($this->loader->getApp())->toBeNull();
});

it('returns null on second load() call (idempotent)', function (): void {
    // File declares no class at all — class detection returns null.
    file_put_contents($this->tmpDir . '/app/App.php', '<?php // stub');

    $first = $this->loader->load($this->paths, $this->builder);
    $second = $this->loader->load($this->paths, $this->builder);

    expect($first)->toBeNull();
    expect($second)->toBeNull();
});

it('loads a valid App class and exposes it via getApp()', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\SpyApp {}",
    );

    $app = $this->loader->load($this->paths, $this->builder);

    expect($app)->toBeInstanceOf(SpyApp::class);
    expect($this->loader->getApp())->toBe($app);
});

it('throws when app/App.php exists but declares a non-SporaExtension class', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        '<?php class NotAnApp {}',
    );

    expect(fn() => $this->loader->load($this->paths, $this->builder))
        ->toThrow(\Spora\Extensions\Exceptions\InvalidAppClassException::class);
});

it('returns null when app/App.php exists but declares no class', function (): void {
    // Empty App.php is treated as "no App installed" — silent no-op, same
    // as the file-not-exists case above.
    file_put_contents($this->tmpDir . '/app/App.php', '<?php // no class here');

    expect($this->loader->load($this->paths, $this->builder))->toBeNull();
});

it('accepts an App that extends AbstractExtension without explicitly implements AppInterface', function (): void {
    // PlainApp extends AbstractExtension but does NOT implement AppInterface.
    // AppLoader must accept it because AbstractExtension implements
    // SporaExtensionInterface, which is the actual acceptance check.
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\PlainApp {}",
    );

    $app = $this->loader->load($this->paths, $this->builder);

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

    $app = $this->loader->load($this->paths, $this->builder);

    expect($app)->toBeInstanceOf($this->appClass);
    expect($app)->toBeInstanceOf(SporaExtensionInterface::class);
});

it('registerRoutes() and boot() are silent no-ops without a loaded App', function (): void {
    // After PR-3 the deprecated register/routes/boot hooks were removed in
    // favour of PSR-14 events. Without a loaded App, the loader should
    // still accept the calls without throwing.
    expect(fn() => $this->loader->registerRoutes(
        new \Spora\Core\MiddlewareRouteCollector(
            new \FastRoute\RouteParser\Std(),
            new \FastRoute\DataGenerator\GroupCountBased(),
        ),
    ))->not->toThrow(Throwable::class);
    expect(fn() => $this->loader->boot())->not->toThrow(Throwable::class);
    expect(fn() => $this->loader->boot(new class implements \Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            return null;
        }
        public function has(string $id): bool
        {
            return false;
        }
    }))->not->toThrow(Throwable::class);
});

it('registerRoutes() dispatches RoutesRegisteringEvent after App load', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\SpyApp {}",
    );

    $this->loader->load($this->paths, $this->builder);

    $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
    $loader     = new AppLoader($dispatcher);
    (new ReflectionProperty($loader, 'app'))->setValue($loader, $this->loader->getApp());

    $fired = false;
    $dispatcher->addListener(\Spora\Events\RoutesRegisteringEvent::class, function () use (&$fired): void {
        $fired = true;
    });

    $loader->registerRoutes(new \Spora\Core\MiddlewareRouteCollector(
        new \FastRoute\RouteParser\Std(),
        new \FastRoute\DataGenerator\GroupCountBased(),
    ));

    expect($fired)->toBeTrue();
});

it('boot() is idempotent within a process', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\SpyApp {}",
    );

    $this->loader->load($this->paths, $this->builder);

    $this->loader->boot();
    $this->loader->boot();
    $this->loader->boot();

    expect($this->loader->getApp())->toBeInstanceOf(SpyApp::class);
});
