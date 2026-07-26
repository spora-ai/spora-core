<?php

declare(strict_types=1);

namespace Spora\Plugins;

use DI\ContainerBuilder;
use ReflectionClass;
use RuntimeException;
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
     * Absolute path of the directory containing this plugin's `plugin.json`
     * manifest. {@see PluginLoader::instantiatePlugin()} populates it on
     * boot; tests / direct instantiation may pass `null`, in which case
     * {@see pluginDir()} falls back to the parent of the entry-point file.
     */
    private readonly ?string $pluginDirPath;

    public function __construct(?string $pluginDir = null)
    {
        $this->pluginDirPath = $pluginDir;
    }

    /**
     * Absolute path to this plugin's root directory (the directory holding
     * `plugin.json`). Use this to build paths under the plugin without
     * reaching for `ReflectionClass` — e.g. `$this->pluginDir() . '/skills'`.
     */
    protected function pluginDir(): string
    {
        if ($this->pluginDirPath !== null) {
            return $this->pluginDirPath;
        }

        // Fallback for direct instantiation (tests, fixtures): derive from
        // the entry-point file location. Plugin.php lives at <root>/src/Plugin.php
        // per the installer's PSR-4 convention, so go up one level.
        $file = (new ReflectionClass($this))->getFileName();
        if ($file === false) {
            throw new RuntimeException(sprintf(
                'Cannot resolve plugin directory for %s: not instantiated by PluginLoader and the entry-point file is unknown.',
                static::class,
            ));
        }

        return \dirname($file) . '/..';
    }

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
     * Absolute paths to agent-template files (.json / .yaml / .yml) this
     * plugin ships. The scanner reads depth-0 from each path. Templates
     * declare tool activations and per-operation auto-approve defaults;
     * settings (passwords, secrets) are NEVER exported or imported —
     * recipients must configure them in Settings → Tools after import.
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [];
    }

    /**
     * Absolute paths to skill directories this plugin ships. The
     * scanner walks each directory depth-1 and treats immediate
     * children as skill roots (each must contain a SKILL.md).
     *
     * @return string[]
     */
    public function skillPaths(): array
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
