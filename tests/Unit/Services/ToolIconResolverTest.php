<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigNameResolver;
use Spora\Services\ToolIconResolver;
use Tests\Fixtures\Icons\TestCalendarTool;

/**
 * ToolIconResolver — 3-layer fallback chain
 *   1. tool.icon   (#[Tool(icon: ...)] on the *Tool class)
 *   2. plugin.icon (owning plugin's plugin.json `icon` field)
 *   3. null        (frontend's <Icon> falls back to 'puzzle')
 */
it('layer 1: returns the icon declared via #[Tool(icon: ...)] on the class', function (): void {
    $resolver = makeIconResolver(toolClasses: [TestCalendarTool::class]);

    expect($resolver->resolve(TestCalendarTool::class))->toBe('calendar');
});

it('layer 2: falls back to the owning plugin\'s plugin.json icon when the tool has no #[Tool(icon: ...)]', function (): void {
    $pluginDir = BASE_PATH . '/tests/Fixtures/plugin_with_icon';
    $loader = new PluginLoader([$pluginDir], null);
    $loader->boot();

    // TestCalendarTool HAS #[Tool(icon: 'calendar')] — so layer 1 wins regardless of plugin.
    // For a true layer-2 test we need a tool class WITHOUT #[Tool(icon: ...)] that the
    // plugin owns. Reuse the existing TestTool fixture, declared without `icon:`.
    $resolver = makeIconResolverWithPlugin(
        toolClasses: [\Tests\Fixtures\TestTool::class],
        pluginLoader: $loader,
        pluginToolClass: \Tests\Fixtures\TestTool::class,
    );

    expect($resolver->resolve(\Tests\Fixtures\TestTool::class))->toBe('mail');
});

it('layer 1 wins over layer 2 when both are set', function (): void {
    $pluginDir = BASE_PATH . '/tests/Fixtures/plugin_with_icon';
    $loader = new PluginLoader([$pluginDir], null);
    $loader->boot();

    // TestCalendarTool has #[Tool(icon: 'calendar')] AND the plugin.json has icon: 'mail'.
    // Layer 1 must win.
    $resolver = makeIconResolverWithPlugin(
        toolClasses: [TestCalendarTool::class],
        pluginLoader: $loader,
        pluginToolClass: TestCalendarTool::class,
    );

    expect($resolver->resolve(TestCalendarTool::class))->toBe('calendar');
});

it('layer 3: returns null when neither layer 1 nor layer 2 declares an icon', function (): void {
    // ToolsPlugin contributes TestTool which has no #[Tool(icon: ...)].
    // The plugin's plugin.json has no `icon` field either → layer 3.
    $pluginDir = BASE_PATH . '/tests/Fixtures/plugins_with_tools';
    $loader = new PluginLoader([$pluginDir], null);
    $loader->boot();

    $resolver = makeIconResolverWithPlugin(
        toolClasses: [\Tests\Fixtures\TestTool::class],
        pluginLoader: $loader,
        pluginToolClass: \Tests\Fixtures\TestTool::class,
    );

    expect($resolver->resolve(\Tests\Fixtures\TestTool::class))->toBeNull();
});

it('layer 3: returns null for a core (non-plugin) tool with no #[Tool(icon: ...)]', function (): void {
    $resolver = makeIconResolver(toolClasses: []);

    expect($resolver->resolve('Some\\NonExistent\\ToolClass'))->toBeNull();
});

it('layer 3: returns null for an unknown class', function (): void {
    $resolver = makeIconResolver(toolClasses: []);

    expect($resolver->resolve('Tests\\Fixtures\\DoesNotExist'))->toBeNull();
});

/**
 * Build a resolver that owns the listed tool classes but has NO plugin loader
 * (so layer 2 always returns null). Used for layer-1-only assertions.
 *
 * @param list<string> $toolClasses
 */
function makeIconResolver(array $toolClasses): ToolIconResolver
{
    return new ToolIconResolver(
        new ToolConfigNameResolver(new NullLogger(), $toolClasses),
        // Layer 2 must always return null in this resolver — pass a loader with no
        // booted plugins, so getSlugForToolClass() returns null for every class.
        new PluginLoader(['/tmp/spora_no_plugins_' . uniqid()], null),
    );
}

/**
 * Build a resolver with an externally-supplied PluginLoader so layer 2 can resolve.
 *
 * @param list<string> $toolClasses
 */
function makeIconResolverWithPlugin(
    array $toolClasses,
    PluginLoader $pluginLoader,
    string $pluginToolClass,
): ToolIconResolver {
    return new ToolIconResolver(
        new ToolConfigNameResolver(new NullLogger(), $toolClasses),
        $pluginLoader,
    );
}