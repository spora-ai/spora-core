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
 * Hook lifecycle (all four are now actually invoked at boot — see
 * {@see PluginLoader::registerPlugins()}, {@see PluginLoader::registerRoutes()},
 * {@see PluginLoader::bootExtensions()}, and the AppRegistry merge in
 * {@see \Spora\Core\ContainerDefinitions::all()}):
 *
 * - `register(ContainerBuilder)` → runs once per process, BEFORE the DI
 *   container is built. Use this to add bindings (`$builder->addDefinitions`)
 *   so plugin tools can be autowired. The container is not yet resolvable
 *   here — use `boot()` for post-build init.
 * - `apps()` → merged into the host's AppRegistry at container build time
 *   so plugin-supplied admin panels surface in `GET /api/v1/apps`.
 * - `routes(MiddlewareRouteCollector)` → runs per request, after the project's
 *   App routes are registered. Plugin routes can extend or override them.
 * - `boot()` → runs per request, after the App boots. Idempotent within a
 *   process. Use this for stateful init that needs container services
 *   (Database, LoggerInterface, etc.).
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
     * host application. Invoked once per process during boot, BEFORE the
     * container is built. Add bindings via `$builder->addDefinitions([...])`.
     * The container is not yet resolvable here — use `boot()` for any
     * post-build init that needs live services.
     */
    public function register(ContainerBuilder $builder): void {}

    /**
     * UI side-panels this plugin contributes to the App Registry. Merged into
     * the host's AppRegistry at container build time. Return [] unless the
     * plugin ships new admin panels.
     *
     * @return array<class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array
    {
        return [];
    }

    /**
     * Register HTTP routes into the running middleware collector. Invoked
     * per request, after the project's App routes are registered. Plugins
     * can override or extend App-registered routes.
     */
    public function routes(MiddlewareRouteCollector $routes): void {}

    /**
     * Lifecycle hook fired once per request after the DI container is built
     * and the App has booted, but before the request is dispatched. Use for
     * stateful init that needs container services. Idempotent within a process
     * (subsequent calls in the same process are no-ops).
     */
    public function boot(): void {}
}
