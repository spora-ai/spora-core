<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use Spora\Plugins\AbstractPlugin;
use Spora\Tools\ToolInterface;
use Tests\Fixtures\TestTool;

test('getName() derives from the unqualified class name with the Plugin suffix stripped', function (): void {
    $plugin = new DemoPlugin();

    expect($plugin->getName())->toBe('Demo');
});

test('getName() returns the unqualified class name when no Plugin suffix is present', function (): void {
    $plugin = new Plain();

    expect($plugin->getName())->toBe('Plain');
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
