<?php

declare(strict_types=1);

namespace Spora\Extensions;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\Paths;
use Spora\Events\BootingEvent;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Extensions\Exceptions\InvalidAppClassException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Discovers and boots the project-level App extension at `<BASE_PATH>/app/App.php`.
 *
 * Discovery is reflection-based — no manifest, no slug, no plugin.json — because
 * the App is a one-per-installation concern, unlike Composer-distributed plugins.
 *
 * The three side-effect hooks (`register()`, `routes()`, `boot()`) were moved
 * to PSR-14 events in 1.0. The App opts in by implementing
 * `Symfony\Component\EventDispatcher\EventSubscriberInterface` and subscribing
 * to `ContainerBuildingEvent`, `RoutesRegisteringEvent`, and `BootingEvent`.
 * See `spora-workspace/plans/extension-interface-events.md` for the migration
 * guide. The class names below are unchanged — only the wiring is.
 *
 * Hooks are applied in this order so the App's contributions are baked into
 * the compiled container:
 *
 *   1. `ContainerBuildingEvent` fires once per process, BEFORE build. The App
 *      may register DI bindings via the event's `builder()` accessor.
 *   2. After the container is built, `RoutesRegisteringEvent` and then
 *      `BootingEvent` fire per request with the live collector and container.
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

    private readonly EventDispatcher $dispatcher;

    /**
     * The dispatcher is required in the constructor (not at wire time) so
     * `wireEventSubscribers()` can rely on it being non-null without a
     * null-check on the hot path. Tests can pass a fresh dispatcher to
     * observe listener wiring without booting the full Kernel.
     */
    public function __construct(?EventDispatcher $dispatcher = null)
    {
        $this->dispatcher = $dispatcher ?? new EventDispatcher();
    }

    /**
     * Discover the App. Returns the loaded App instance, or null if no
     * app/App.php exists. Does NOT dispatch lifecycle events — see
     * {@see \Spora\Plugins\PluginLoader::registerPlugins()}, which is the
     * single dispatch site for `ContainerBuildingEvent`. Dispatching here
     * would double-fire every plugin's subscriber (AppLoader and
     * PluginLoader share the dispatcher).
     *
     * Called once by the Kernel BEFORE the container is built. $paths and
     * $builder are passed here (not via the constructor) because:
     * - Subscribers to `ContainerBuildingEvent` must mutate the
     *   ContainerBuilder BEFORE build.
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

    /**
     * No-op placeholder retained for Kernel API compatibility.
     *
     * `RoutesRegisteringEvent` is dispatched from
     * {@see \Spora\Plugins\PluginLoader::registerRoutes()} — the single
     * dispatch site for the request lifecycle. AppLoader's prior dispatch
     * here caused every plugin's `onRoutesRegistering` to fire twice on
     * the shared dispatcher, producing duplicate FastRoute entries.
     */
    public function registerRoutes(MiddlewareRouteCollector $routes): void {}

    /**
     * No-op placeholder retained for Kernel API compatibility.
     *
     * `BootingEvent` is dispatched from
     * {@see \Spora\Plugins\PluginLoader::bootExtensions()} — the single
     * dispatch site for the boot phase. The idempotent `$booted` guard
     * stays so callers passing null continue to get silent-acceptance
     * semantics.
     *
     * Safe to use container services inside `BootingEvent` listeners
     * (the listener fires after `Kernel::__construct` builds the container).
     */
    public function boot(?ContainerInterface $container = null): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
    }

    private bool $appSubscriberWired = false;

    /**
     * Attach the loaded App to the dispatcher when it implements
     * {@see EventSubscriberInterface}. Mirrors
     * {@see \Spora\Plugins\PluginLoader::wireEventSubscribers()} — same
     * PSR-14 wiring, idempotent across calls.
     */
    public function wireEventSubscribers(): void
    {
        if ($this->appSubscriberWired || !$this->app instanceof EventSubscriberInterface) {
            return;
        }
        $this->dispatcher->addSubscriber($this->app);
        $this->appSubscriberWired = true;
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

        // The require_once autoloads the abstract parent (e.g.
        // AbstractExtension) into the same diff; without isAbstract(), that
        // parent wins array_key_last() and boot crashes with
        // "Cannot instantiate abstract class".
        $candidates = array_filter(
            $newlyDeclared,
            static function (string $c): bool {
                if (!is_subclass_of($c, SporaExtensionInterface::class)) {
                    return false;
                }
                return !(new ReflectionClass($c))->isAbstract();
            },
        );

        if (empty($candidates)) {
            return null;
        }

        return $candidates[array_key_last($candidates)];
    }
}
