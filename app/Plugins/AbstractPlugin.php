<?php

declare(strict_types=1);

namespace Spora\Plugins;

use DI\ContainerBuilder;
use ReflectionClass;
use Spora\Core\MiddlewareRouteCollector;

/**
 * Base implementation of {@see PluginInterface} with sensible no-op defaults
 * for the optional extension points.
 *
 * Plugins SHOULD extend this class and override only the hooks they actually
 * use (typically {@see getName()} and {@see tools()}). Direct implementations
 * of PluginInterface remain valid — the interface is unchanged for backward
 * compatibility — but every direct implementer ends up writing the same six
 * empty methods.
 *
 * Inherited defaults from {@see SporaExtensionInterface}:
 * - `apps(): array` → []
 * - `routes(MiddlewareRouteCollector): void` → no-op
 * - `boot(): void` → no-op
 */
abstract class AbstractPlugin implements PluginInterface
{
    /**
     * Default name: the unqualified class name with a trailing "Plugin" suffix
     * stripped (e.g. SkeletonPlugin → "Skeleton"). Subclasses should override
     * with their human-facing brand name (e.g. "MiniMax", "Tavily Search").
     */
    public function getName(): string
    {
        $short = (new ReflectionClass($this))->getShortName();
        if (str_ends_with($short, 'Plugin')) {
            $short = substr($short, 0, -strlen('Plugin'));
        }
        return $short !== '' ? $short : 'Plugin';
    }

    /**
     * PSR-4 autoload mappings the plugin contributes at runtime, in addition
     * to whatever its composer.json declares. Most plugins can leave this empty.
     *
     * @return array<string, string>
     */
    public function autoload(): array
    {
        return [];
    }

    /**
     * Tool classes this plugin contributes to the Tool Registry.
     *
     * @return array<class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * LLM driver classes this plugin contributes. Most plugins leave this empty.
     *
     * @return array<string, class-string<\Spora\Drivers\LLMDriverInterface>>
     */
    public function drivers(): array
    {
        return [];
    }

    /**
     * Absolute paths to recipe directories or files this plugin ships.
     *
     * @return string[]
     */
    public function recipePaths(): array
    {
        return [];
    }

    /**
     * Bump whenever new migration files are added under {@see migrationsPath()}.
     * Return 0 (the default) if the plugin has no database schema.
     */
    public function schemaVersion(): int
    {
        return 0;
    }

    /**
     * Absolute path to the directory containing this plugin's Laravel
     * migration files. Return null (the default) if the plugin has no
     * database schema.
     */
    public function migrationsPath(): ?string
    {
        return null;
    }

    /**
     * Register arbitrary DI bindings, middleware, or services into the
     * host application. No-op by default.
     */
    public function register(ContainerBuilder $builder): void {}

    /**
     * UI side-panels this plugin contributes to the App Registry.
     * No-op by default — plugins return [] unless they ship new admin panels.
     *
     * @return array<class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array
    {
        return [];
    }

    /**
     * Register HTTP routes into the running middleware collector.
     * No-op by default. Plugins that need their own endpoints override this.
     */
    public function routes(MiddlewareRouteCollector $routes): void {}

    /**
     * Lifecycle hook fired once after the DI container is built and before the
     * first request is dispatched. No-op by default — override for stateful init.
     */
    public function boot(): void {}
}
