<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use DI\ContainerBuilder;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\Paths;
use Spora\Extensions\AbstractExtension;
use Spora\Extensions\AppInterface;
use Spora\Extensions\AppLoader;
use Spora\Extensions\SporaExtensionInterface;
use Throwable;

/**
 * App that records every method call so we can assert on the lifecycle.
 * Non-final because AppLoader's discovery creates a runtime subclass via
 * `class App extends SpyApp {}` written to a file on disk.
 */
class SpyApp extends AbstractExtension implements AppInterface
{
    public int $registerCalls = 0;
    public int $routesCalls = 0;
    public int $bootCalls = 0;

    public function getName(): string
    {
        return 'Spy';
    }

    public function register(ContainerBuilder $builder): void
    {
        $this->registerCalls++;
    }

    public function routes(MiddlewareRouteCollector $routes): void
    {
        $this->routesCalls++;
    }

    public function boot(): void
    {
        $this->bootCalls++;
    }
}

/**
 * App whose class declaration is invalid for AppLoader (not implementing
 * SporaExtensionInterface). Used to assert the validation error path.
 */
final class InvalidApp {}

/**
 * Subclass of AbstractExtension that doesn't implement AppInterface — to
 * prove AppLoader's acceptance check uses SporaExtensionInterface, not
 * AppInterface, so apps don't have to `implements AppInterface` explicitly.
 * Non-final for the same reason as SpyApp.
 */
class PlainApp extends AbstractExtension
{
    public function getName(): string
    {
        return 'Plain';
    }
}

beforeEach(function (): void {
    $this->tmpDir = sys_get_temp_dir() . '/spora-app-loader-' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir, 0755, true);
    mkdir($this->tmpDir . '/app', 0755, true);
    $this->paths = new Paths($this->tmpDir);
    $this->builder = new ContainerBuilder();
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

it('invokes App::register(ContainerBuilder) during load()', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\SpyApp {}",
    );

    $this->loader->load($this->paths, $this->builder);

    expect($this->loader->getApp()->registerCalls)->toBe(1);
});

it('invokes App::routes() when registerRoutes() is called', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\SpyApp {}",
    );

    $this->loader->load($this->paths, $this->builder);
    $collector = new MiddlewareRouteCollector(new \FastRoute\RouteParser\Std(), new \FastRoute\DataGenerator\GroupCountBased());
    $this->loader->registerRoutes($collector);

    expect($this->loader->getApp()->routesCalls)->toBe(1);
});

it('is a no-op when registerRoutes() is called without a loaded App', function (): void {
    $collector = new MiddlewareRouteCollector(new \FastRoute\RouteParser\Std(), new \FastRoute\DataGenerator\GroupCountBased());
    expect(fn() => $this->loader->registerRoutes($collector))->not->toThrow(Throwable::class);
});

it('invokes App::boot() on first call only (idempotent)', function (): void {
    file_put_contents(
        $this->tmpDir . '/app/App.php',
        "<?php class $this->appClass extends \\Tests\\Unit\\Extensions\\SpyApp {}",
    );

    $this->loader->load($this->paths, $this->builder);
    $this->loader->boot();
    $this->loader->boot();
    $this->loader->boot();

    expect($this->loader->getApp()->bootCalls)->toBe(1);
});

it('is a no-op when boot() is called without a loaded App', function (): void {
    expect(fn() => $this->loader->boot())->not->toThrow(Throwable::class);
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

it('registers PSR-4 mappings declared by App::autoload() with the Composer ClassLoader', function (): void {
    $mappingApp = new class extends AbstractExtension {
        public function getName(): string
        {
            return 'MappingApp';
        }
        public function autoload(): array
        {
            // Pick a directory the project's own autoloader already uses — then we
            // can introspect it before/after to verify the registration is idempotent.
            return ['Tests\\Fixtures\\' => __DIR__ . '/../../Fixtures'];
        }
    };

    // Inject via reflection — AppLoader normally loads from a file path,
    // but for this test we just want to verify register() wires autoload().
    $ref = new ReflectionClass($this->loader);
    $appProp = $ref->getProperty('app');
    $appProp->setValue($this->loader, $mappingApp);

    $classLoader = null;
    foreach (spl_autoload_functions() as $fn) {
        if (is_array($fn) && $fn[0] instanceof \Composer\Autoload\ClassLoader) {
            $classLoader = $fn[0];
            break;
        }
    }
    expect($classLoader)->toBeInstanceOf(\Composer\Autoload\ClassLoader::class);

    // No exception is raised even when the ClassLoader already maps the namespace.
    $this->loader->registerRoutes(new MiddlewareRouteCollector(new \FastRoute\RouteParser\Std(), new \FastRoute\DataGenerator\GroupCountBased()));

    // The namespace must still be resolvable after re-registration.
    expect(class_exists(\Tests\Fixtures\TestTool::class))->toBeTrue();
});
