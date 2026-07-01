<?php

declare(strict_types=1);

namespace Spora\Extensions;

use DI\ContainerBuilder;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\Paths;
use Spora\Extensions\Exceptions\InvalidAppClassException;

/**
 * Discovers and boots the project-level App extension at `<BASE_PATH>/app/App.php`.
 *
 * Discovery is reflection-based — no manifest, no slug, no plugin.json — because
 * the App is a one-per-installation concern, unlike Composer-distributed plugins.
 *
 * Hooks are applied in this order so the App's contributions are baked into the
 * compiled container:
 *
 *   1. App's PSR-4 mappings are registered with Composer's ClassLoader so e.g.
 *      `App\Tools\Greeter` is resolvable during container build.
 *   2. App::register(ContainerBuilder) is called BEFORE build — its bindings are
 *      merged into the container that PHP-DI compiles.
 *   3. After the container is built, Kernel calls App::routes() and App::boot()
 *      with the now-live container and route collector.
 *
 * No app/App.php? AppLoader is a silent no-op — Spora runs as it always has.
 */
final class AppLoader
{
    /**
     * Typed as the shared extension contract (not AppInterface) so any
     * AbstractExtension descendant is accepted; AppInterface is a marker,
     * not a stricter type.
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
     * @throws InvalidAppClassException When app/App.php exists but does not declare
     *                                  a class implementing {@see SporaExtensionInterface}.
     */
    public function load(Paths $paths, ContainerBuilder $builder): ?SporaExtensionInterface
    {
        if ($this->app !== null) {
            return $this->app;
        }

        $app = $this->discoverApp($paths);
        if ($app === null) {
            return null;
        }

        // PSR-4 mappings must be registered before App::register() so the App's
        // own tool classes are resolvable during container build.
        $this->registerAutoloadMappings($app);

        // FINALLY wired — App::register() applies DI bindings to the
        // ContainerBuilder BEFORE the container is built.
        $app->register($builder);

        $this->app = $app;
        return $this->app;
    }

    /**
     * Discover and instantiate the App class declared in app/App.php.
     *
     * Returns null when no App is installed (file missing, or file declares
     * no class) — both cases are silent no-ops in the framework's view.
     * Throws InvalidAppClassException when the file declares a class that
     * does not implement SporaExtensionInterface — that's a developer error.
     */
    private function discoverApp(Paths $paths): ?SporaExtensionInterface
    {
        $appFile = $paths->app('App.php');
        if (!is_file($appFile)) {
            return null;
        }

        // Snapshot declared classes BEFORE require_once so we can detect what
        // the App file itself contributed.
        $before = get_declared_classes();
        require_once $appFile;
        $newlyDeclared = array_values(array_diff(get_declared_classes(), $before));

        if (empty($newlyDeclared)) {
            return null;
        }

        $fqcn = $this->resolveAppFqcn($newlyDeclared);
        if ($fqcn === null) {
            throw new InvalidAppClassException(sprintf(
                'Class(es) declared in %s (%s) do not implement %s. '
                . 'The App class must implement %s or extend %s.',
                $appFile,
                implode(', ', $newlyDeclared),
                SporaExtensionInterface::class,
                SporaExtensionInterface::class,
                AbstractExtension::class,
            ));
        }

        return new $fqcn();
    }

    private function registerAutoloadMappings(SporaExtensionInterface $app): void
    {
        $classLoader = $this->findClassLoader();
        if ($classLoader === null) {
            return;
        }
        foreach ($app->autoload() as $namespace => $path) {
            $classLoader->addPsr4($namespace, $path);
        }
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
        if (empty($newlyDeclared)) {
            return null;
        }

        // Filter to SporaExtensionInterface implementers that are CONCRETE —
        // the App's parent (e.g. AbstractExtension) is autoloaded along with
        // the App file and shows up in $newlyDeclared, but it is abstract and
        // cannot be instantiated. Without the isAbstract() check,
        // array_key_last() picks the most-recently-declared class which is
        // usually the parent — leading to "Cannot instantiate abstract class"
        // at boot.
        $candidates = array_filter(
            $newlyDeclared,
            static function (string $c): bool {
                if (!is_subclass_of($c, SporaExtensionInterface::class)) {
                    return false;
                }
                return !(new \ReflectionClass($c))->isAbstract();
            },
        );

        if (empty($candidates)) {
            return null;
        }

        return $candidates[array_key_last($candidates)];
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
