<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use DI\ContainerBuilder;
use Spora\Plugins\AbstractPlugin;
use Spora\Tools\ToolInterface;
use Tests\Fixtures\TestTool;
use Throwable;

test('getName() derives from the unqualified class name with the Plugin suffix stripped', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin->getName())->toBe('Demo');
});

test('getName() returns the unqualified class name when no Plugin suffix is present', function (): void {
    $plugin = new Plain();

    expect($plugin->getName())->toBe('Plain');
});

test('autoload() defaults to an empty array', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin->autoload())->toBe([]);
});

test('drivers() defaults to an empty array', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin->drivers())->toBe([]);
});

test('recipePaths() defaults to an empty array', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin->recipePaths())->toBe([]);
});

test('agentTemplatePaths() defaults to an empty array', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin->agentTemplatePaths())->toBe([]);
});

test('schemaVersion() defaults to 0', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin->schemaVersion())->toBe(0);
});

test('migrationsPath() defaults to null', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin->migrationsPath())->toBeNull();
});

test('register() is a no-op (does not throw, accepts a ContainerBuilder)', function (): void {
    $plugin = new DemoPlugin();

    // Pass a real ContainerBuilder — register() must accept it without side effects.
    expect(fn() => $plugin->register(new ContainerBuilder()))->not->toThrow(Throwable::class);
});

test('subclass can override only getName() and tools(), leaving every other method at its default', function (): void {
    $plugin = new class extends AbstractPlugin {
        public function getName(): string
        {
            return 'Custom Brand';
        }

        /** @return array<class-string<ToolInterface>> */
        public function tools(): array
        {
            return [TestTool::class];
        }
    };

    expect($plugin->getName())->toBe('Custom Brand');
    expect($plugin->tools())->toBe([TestTool::class]);
    expect($plugin->autoload())->toBe([]);
    expect($plugin->drivers())->toBe([]);
    expect($plugin->recipePaths())->toBe([]);
    expect($plugin->agentTemplatePaths())->toBe([]);
    expect($plugin->schemaVersion())->toBe(0);
    expect($plugin->migrationsPath())->toBeNull();
});

test('AbstractPlugin implements PluginInterface (so direct implementers stay backward-compatible)', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin)->toBeInstanceOf(\Spora\Plugins\PluginInterface::class);
});

test('apps() defaults to an empty array', function (): void {
    expect((new DemoPlugin())->apps())->toBe([]);
});

test('routes() is a no-op (accepts a route collector, does not throw)', function (): void {
    $plugin = new DemoPlugin();
    $collector = new \Spora\Core\MiddlewareRouteCollector(
        new \FastRoute\RouteParser\Std(),
        new \FastRoute\DataGenerator\GroupCountBased(),
    );
    expect(fn() => $plugin->routes($collector))->not->toThrow(Throwable::class);
});

test('boot() is a no-op', function (): void {
    expect(fn() => (new DemoPlugin())->boot())->not->toThrow(Throwable::class);
});

test('pluginDir() returns the path passed to the constructor (PluginLoader case)', function (): void {
    $exposed = new class ('/var/www/plugins/demo') extends AbstractPlugin {
        public function __construct(private readonly string $testDir)
        {
            parent::__construct($testDir);
        }

        public function exposePluginDir(): string
        {
            return $this->pluginDir();
        }
    };

    expect($exposed->exposePluginDir())->toBe('/var/www/plugins/demo');
});

test('pluginDir() falls back to the parent of the entry-point file when no constructor arg is supplied', function (): void {
    // Create a real subclass that lives in this test file. Its
    // ReflectionClass::getFileName() will point here, so the fallback
    // path resolves to the parent dir of tests/Unit/Plugins/AbstractPluginTest.php.
    $exposed = new class extends AbstractPlugin {
        public function exposePluginDir(): string
        {
            return $this->pluginDir();
        }
    };

    // The literal fallback is `<test_dir>/..` — assert it's a non-empty
    // string and that realpath() collapses it to a directory under
    // tests/Unit/Plugins. We don't pin to an exact suffix so the test
    // stays portable across CI machines.
    $resolved = realpath($exposed->exposePluginDir());
    expect($resolved)->not->toBeFalse();
    expect($resolved)->toEndWith('tests' . DIRECTORY_SEPARATOR . 'Unit');
});
