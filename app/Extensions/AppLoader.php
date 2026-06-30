<?php

declare(strict_types=1);

namespace Spora\Extensions;

use DI\ContainerBuilder;
use RuntimeException;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\Paths;

/**
 * Discovers and boots the project-level App extension at `<BASE_PATH>/app/App.php`.
 *
 * Discovery is reflection-based — no manifest, no slug, no plugin.json — because
 * the App is a one-per-installation concern. The convention:
 *
 *   app/
 *     App.php          (entry point; declares a class implementing AppInterface)
 *     Tools/           (PSR-4 namespace App\Tools\)
 *     Http/Controllers (PSR-4 namespace App\Http\Controllers)
 *
 * Hooks are applied in this order so that the container ends up with the App's
 * contributions baked in:
 *
 *   1. App's PSR-4 mappings are registered with Composer's ClassLoader
 *      (so e.g. `App\Tools\Greeter` is resolvable during container build).
 *   2. App::register(ContainerBuilder) is called BEFORE build — its bindings
 *      are merged into the container that PHP-DI compiles.
 *   3. After the container is built, Kernel calls App::routes() and App::boot()
 *      with the now-live container and route collector.
 *
 * No app/App.php? AppLoader is a silent no-op — Spora runs as it always has.
 */
final class AppLoader
{
    /**
     * The loaded App, typed as the shared extension contract so any
     * AbstractExtension descendant works (AppInterface is an additional
     * marker, not a stricter type).
     *
     * @var SporaExtensionInterface|null
     */
    private ?SporaExtensionInterface $app = null;

    private bool $booted = false;

    /**
     * Empty constructor — AppLoader must be autowireable as a normal
     * container service (PHP-DI calls `new` on it via `$c->get()`), but
     * its `load()` step is the only thing that needs project paths and
     * a ContainerBuilder. Both are passed at call time, not construction,
     * to keep the dependency direction simple.
     */
    public function __construct() {}

    /**
     * Discover and bind the App. Returns the loaded App instance, or null
     * if no app/App.php exists.
     *
     * Called once by the Kernel BEFORE the container is built. $paths and
     * $builder are passed here (not via the constructor) because:
     * - The App's `register()` hook must modify the ContainerBuilder BEFORE
     *   build.
     * - AppLoader itself needs to be resolvable as a normal container
     *   service for the post-build factories that depend on it (Database,
     *   RecipeScanner, AppRegistry, tool_instances).
     *
     * @throws RuntimeException When app/App.php exists but does not declare
     *                           a class implementing {@see SporaExtensionInterface}.
     */
    public function load(Paths $paths, ContainerBuilder $builder): ?SporaExtensionInterface
    {
        if ($this->app !== null) {
            return $this->app;
        }

        $appFile = $paths->app('App.php');
        if (!is_file($appFile)) {
            return null;
        }

        // Snapshot declared classes BEFORE require_once so we can detect what
        // the App file itself contributed.
        $before = get_declared_classes();
        require_once $appFile;
        $after = get_declared_classes();

        $newlyDeclared = array_values(array_diff($after, $before));
        if ($newlyDeclared === []) {
            // The file exists but does not declare any class — treat this
            // the same as "no App installed" (silent no-op). Common in
            // early-boot / stub scenarios.
            return null;
        }

        $fqcn = $this->resolveAppFqcn($newlyDeclared);
        if ($fqcn === null) {
            // The file declared class(es), but none implements
            // SporaExtensionInterface. That's a developer error —
            // they shipped an App.php that doesn't look like an App.
            throw new RuntimeException(sprintf(
                'Class(es) declared in %s (%s) do not implement %s. '
                . 'The App class must implement %s or extend %s.',
                $appFile,
                implode(', ', $newlyDeclared),
                SporaExtensionInterface::class,
                SporaExtensionInterface::class,
                AbstractExtension::class,
            ));
        }

        $this->app = new $fqcn();

        // 1. Register PSR-4 mappings so the App's own classes are resolvable
        //    during container build (the App may have tools that depend on
        //    App\Tools\Foo which only exists once its namespace is mapped).
        $classLoader = $this->findClassLoader();
        if ($classLoader !== null) {
            foreach ($this->app->autoload() as $namespace => $path) {
                $classLoader->addPsr4($namespace, $path);
            }
        }

        // 2. Apply DI bindings. FINALLY wired (was previously declared-but-ignored
        //    on PluginInterface; the same hook now works on App as well).
        $this->app->register($builder);

        return $this->app;
    }

    /**
     * Forward to App::routes(). Called after core routes are registered,
     * before the router is built.
     */
    public function registerRoutes(MiddlewareRouteCollector $routes): void
    {
        $this->app?->routes($routes);
    }

    /**
     * Forward to App::boot(). Called once after the container is built.
     * Safe to use container services inside boot().
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->app?->boot();
    }

    public function getApp(): ?SporaExtensionInterface
    {
        return $this->app;
    }

    /**
     * Pick the App class out of a list of classes newly declared by app/App.php.
     *
     * Caller is responsible for the require_once and for passing the diff
     * between declared classes before and after — that isolates this method
     * from the "require_once is a no-op" problem that would otherwise let
     * us mistakenly pick up an extension class loaded by a previous file
     * in the same process (a long-running worker, or a prior test).
     *
     * Returns null if the file did not declare a usable App class.
     *
     * @param list<string> $newlyDeclared Class FQCNs added by the App file
     */
    private function resolveAppFqcn(array $newlyDeclared): ?string
    {
        if ($newlyDeclared === []) {
            return null;
        }

        // Filter to SporaExtensionInterface implementers, then take the last declared
        // one (most-recent declaration wins — matches typical "one class per file"
        // practice).
        $implementers = array_filter(
            $newlyDeclared,
            static fn(string $c): bool => is_subclass_of($c, SporaExtensionInterface::class),
        );

        if (count($implementers) === 0) {
            return null;
        }

        return $implementers[array_key_last($implementers)];
    }

    private function findClassLoader(): ?\Composer\Autoload\ClassLoader
    {
        foreach (spl_autoload_functions() as $fn) {
            if (is_array($fn) && $fn[0] instanceof \Composer\Autoload\ClassLoader) {
                return $fn[0];
            }
        }
        return null;
    }
}
