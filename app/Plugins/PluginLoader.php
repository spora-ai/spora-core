<?php

declare(strict_types=1);

namespace Spora\Plugins;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Spora\Apps\AppInterface;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Events\BootingEvent;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Discovers and boots PluginInterface implementations from one or more plugin directories.
 *
 * Each plugin lives in its own subdirectory and must ship a plugin.json manifest
 * declaring the entry-point class FQCN. The manifest's `autoload.psr-4` mapping is
 * registered with the Composer classloader before instantiation so the plugin's
 * own classes are resolvable.
 *
 * The directory scan and manifest parse are cached via {@see PluginLoaderCache};
 * a warm boot re-instantiates plugins from a sidecar JSON without re-reading
 * manifests or dispatching `ContainerBuildingEvent`.
 *
 * Directories are scanned in the order given; if the same slug appears in more
 * than one, the first one wins.
 *
 * Boot-time work sits in {@see PluginDiscovery} and the on-disk metadata reads
 * in {@see PluginMetadata}; both bind the maps below by reference, so this
 * class stays their only owner and writer. A snapshot would lose plugins:
 * {@see PluginLoader::boot()} lets a manifest failure escape, and Kernel falls
 * through to a partially populated loader.
 */
final class PluginLoader
{
    /**
     * Loaded plugins, keyed by their manifest slug.
     *
     * @var array<string, PluginInterface>
     */
    private array $plugins = [];

    /**
     * Map of slug => absolute plugin directory.
     *
     * @var array<string, string>
     */
    private array $pluginDirs = [];

    /**
     * Map of slug => parsed manifest array (raw json_decode output, already
     * validated for the required slug + class fields).
     *
     * @var array<string, array<string, mixed>>
     */
    private array $pluginManifests = [];

    private bool $booted = false;

    private readonly PluginLoaderCache $cache;

    private readonly EventDispatcher $dispatcher;

    private readonly PluginMetadata $metadata;

    private readonly PluginDiscovery $discovery;

    /**
     * @param list<string>  $pluginDirectories Absolute paths to scan for `<plugin>/plugin.json`.
     *                                        Non-existent directories are silently skipped.
     * @param ?string       $stampPath        Filesystem path to a stamp file. When set and
     *                                        current, the loader re-instantiates plugins
     *                                        from a sidecar JSON. When null, the loader
     *                                        always performs a full discovery (used in tests).
     * @param ?EventDispatcher $dispatcher    Dispatcher plugins attach {@see EventSubscriberInterface}
     *                                        implementations to. Required in production;
     *                                        tests may pass a fresh dispatcher (or null to
     *                                        auto-create one) to observe listener wiring.
     */
    public function __construct(
        array $pluginDirectories,
        ?string $stampPath = null,
        ?EventDispatcher $dispatcher = null,
    ) {
        $this->cache = new PluginLoaderCache($pluginDirectories, $stampPath);
        $this->dispatcher = $dispatcher ?? new EventDispatcher();
        $this->metadata = new PluginMetadata($this->pluginDirs, $this->pluginManifests);
        $this->discovery = new PluginDiscovery(
            $this->cache,
            $this->plugins,
            $this->pluginDirs,
            $this->pluginManifests,
        );
    }

    /**
     * Callers hold the loader, not this collaborator, so the accessor hands out
     * the live instance. Wrapping each of its methods here instead would double
     * this class for no behaviour change.
     */
    public function metadata(): PluginMetadata
    {
        return $this->metadata;
    }

    /**
     * Discover, autoload, and boot all plugins.
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $discovered = $this->cache->collectManifests();

        if ($this->cache->isCurrent($discovered)) {
            $this->discovery->restoreFromSidecar();
            return;
        }

        $this->discovery->loadDiscovered($discovered);

        $this->cache->write($discovered, $this->discovery->buildSidecarEntries());
    }

    /**
     * All tool class FQCNs contributed by loaded plugins.
     *
     * @return list<class-string>
     */
    public function toolClasses(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->tools() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Speech-to-text provider class FQCNs contributed by loaded plugins.
     *
     * Mirrors {@see toolClasses()} — the speech registry uses this list
     * to discover plugin-contributed
     * {@see \Spora\Speech\SpeechToTextProviderInterface} implementations.
     *
     * Core ships its own {@see \Spora\Speech\OpenAiCompatibleTranscriber}
     * for the OpenAI-multipart family (Mistral, OpenAI Whisper, Groq,
     * Lemonfox, Fireworks, LocalAI, future) — it lives in
     * `speech_to_text_provider_classes` (not here) so adding a new
     * OpenAI-multipart vendor is a configuration row, not a code change.
     * Plugins that need a bespoke wire shape beyond the OpenAI multipart
     * (today: {@see Muse\MuseTranscribeProvider} for the
     * Meta Muse STT endpoint with its custom two-part multipart and
     * ffmpeg preprocessing) contribute their provider classes here.
     *
     * @return list<class-string>
     */
    public function speechToTextProviderClasses(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->speechToTextProviders() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * All agent-template directory roots contributed by loaded plugins, paired
     * with the contributing plugin's slug. Mirrors {@see skillPaths()}; the
     * container aggregates these alongside
     * {@see \Spora\Core\Paths::agentTemplateRoots()}.
     *
     * @return list<array{path: string, source: string}>
     */
    public function agentTemplatePaths(): array
    {
        $paths = [];

        foreach ($this->plugins as $slug => $plugin) {
            foreach ($plugin->agentTemplatePaths() as $path) {
                $paths[] = ['path' => $path, 'source' => $slug];
            }
        }

        return $paths;
    }

    /**
     * All skill directory roots contributed by loaded plugins, paired with
     * the contributing plugin's slug. Mirrors {@see agentTemplatePaths()};
     * the scanner aggregates these alongside the project-level and
     * framework-bundled skill roots from {@see \Spora\Core\Paths::skillsPaths()}.
     *
     * The `source` label is what {@see \Spora\Skills\SkillScanner} uses
     * to bucket same-named skills (project wins over framework wins over
     * plugin) and to tag the resulting {@see \Spora\Skills\Skill} objects.
     *
     * @return list<array{path: string, source: string}>
     */
    public function skillPaths(): array
    {
        $paths = [];

        foreach ($this->plugins as $slug => $plugin) {
            foreach ($plugin->skillPaths() as $path) {
                $paths[] = ['path' => $path, 'source' => $slug];
            }
        }

        return $paths;
    }

    /**
     * All {@see \Spora\Skills\SkillProviderInterface} classes contributed by
     * loaded plugins, as a static class list. Mirrors
     * {@see speechToTextProviderClasses()}.
     *
     * The container reads this once at build time and resolves the classes
     * itself. A mutable registry populated from `boot()` would arrive too late
     * — the registry that would read it is built in the same pass — and would
     * need a second owner. That is also why the provider seam is a data hook
     * rather than a fourth PSR-14 event: nothing wires subscribers before the
     * container is built, so a `boot()`-fired event reaches no listeners.
     *
     * @return list<class-string<\Spora\Skills\SkillProviderInterface>>
     */
    public function skillProviderClasses(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->skillProviders() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * All {@see \Spora\Search\SearchProviderInterface} class FQCNs contributed by
     * loaded plugins, merged into the container's search provider list.
     *
     * Mirrors {@see skillProviderClasses()} rather than being generic over both:
     * the two hooks have independent precedence, and one generic helper would
     * have to invent a merge order for them.
     *
     * @return list<class-string<\Spora\Search\SearchProviderInterface>>
     */
    public function searchProviderClasses(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->searchProviders() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * All admin-panel App class FQCNs contributed by loaded plugins.
     * Merged into the host AppRegistry at container build time.
     *
     * @return list<class-string>
     */
    public function appClasses(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->apps() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * All loaded plugins, indexed by their manifest slug.
     *
     * @return array<string, PluginInterface>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Resolve the plugin slug that owns a given app instance, or null
     * if no plugin claims the class (e.g. core-owned apps registered
     * directly with the AppRegistry).
     *
     * `slug` is the on-disk bundle directory, distinct from the app's
     * `name()` (which is the route key) — the SPA uses it to build the
     * `/plugins/<slug>/<entry>` bundle URL, so the two are not
     * interchangeable.
     */
    public function getSlugForApp(AppInterface $app): ?string
    {
        $class = $app::class;
        foreach ($this->plugins as $slug => $plugin) {
            foreach ($plugin->apps() as $appClass) {
                if ($appClass === $class) {
                    return $slug;
                }
            }
        }
        return null;
    }

    /**
     * Map a tool FQCN to the slug of the plugin that ships it, or null
     * when no loaded plugin owns it (built-in core tool, or a tool that
     * was uninstalled after the agent was last edited).
     *
     * Symmetric to {@see getSlugForApp()}; both walk the loaded plugin
     * graph to answer a class-name lookup. The template exporter uses
     * this to populate `required_plugins` so a re-import on another
     * instance lists exactly which plugins must be installed.
     */
    public function getSlugForToolClass(string $toolClass): ?string
    {
        foreach ($this->plugins as $slug => $plugin) {
            foreach ($plugin->tools() as $class) {
                if ($class === $toolClass) {
                    return $slug;
                }
            }
        }
        return null;
    }

    /**
     * All plugin migration paths and their declared schema versions, for use by DatabaseSchemaInstaller.
     * Keyed by plugin slug — the slug is the component name written to schema_versions
     * and the required prefix for migration filenames.
     *
     * @return array<string, array{path: string, version: int}>
     */
    public function pluginMigrationPaths(): array
    {
        $result = [];

        foreach ($this->plugins as $slug => $plugin) {
            if ($plugin->schemaVersion() > 0 && $plugin->migrationsPath() !== null) {
                $result[$slug] = [
                    'path'    => $plugin->migrationsPath(),
                    'version' => $plugin->schemaVersion(),
                ];
            }
        }

        return $result;
    }

    /**
     * Dispatch {@see ContainerBuildingEvent}. Called by Kernel AFTER
     * appLoader->load() (so the App's subscribers ran first) and BEFORE
     * $builder->build() (so plugin DI bindings are part of the container
     * graph).
     *
     * Plugins must implement {@see EventSubscriberInterface} and subscribe
     * to ContainerBuildingEvent to register DI bindings — there is no
     * per-plugin fallback hook. See `spora-workspace/plans/extension-interface-events.md`
     * for the migration guide.
     */
    public function registerPlugins(ContainerBuilder $builder): void
    {
        $this->dispatchWithTolerance(new ContainerBuildingEvent($builder));
    }

    /**
     * Dispatch {@see RoutesRegisteringEvent}. Called per-request by
     * Kernel::buildRouter() after the project's App routes have been
     * registered — plugin routes can override or extend those.
     *
     * Same opt-in shape as {@see registerPlugins()}: subscribers only.
     */
    public function registerRoutes(MiddlewareRouteCollector $routes): void
    {
        $this->dispatchWithTolerance(new RoutesRegisteringEvent($routes));
    }

    private bool $extensionsBooted = false;

    /**
     * Dispatch {@see BootingEvent}. Called per-request by Kernel::handle()
     * after the project's App has booted. Idempotent — repeat calls within
     * the same process are no-ops.
     *
     * The container is required to dispatch BootingEvent; passing null is
     * permitted only so legacy callers (and idempotency tests that do not
     * care about the event) can still invoke the method without bootstrapping
     * a full container.
     */
    public function bootExtensions(?ContainerInterface $container = null): void
    {
        if ($this->extensionsBooted) {
            return;
        }
        $this->extensionsBooted = true;

        if ($container !== null) {
            $this->dispatchWithTolerance(new BootingEvent($container));
        }
    }

    /** @var array<int, true> Spl_object_id set, guards against duplicate listeners on long-running workers. */
    private array $wiredSubscriberIds = [];

    /**
     * Dispatch an event, swallowing listener exceptions so a single bad
     * subscriber doesn't abort the rest of the plugin set. Pre-1.0, the
     * per-plugin `register()`/`routes()`/`boot()` hooks were wrapped in
     * try/catch in the same spirit; this restores that tolerance on the
     * new event-dispatch path.
     */
    private function dispatchWithTolerance(object $event): void
    {
        try {
            $this->dispatcher->dispatch($event, $event::class);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[spora plugin listener] %s failed: %s',
                $event::class,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Attach every loaded plugin implementing {@see EventSubscriberInterface}
     * to the dispatcher so it receives lifecycle events.
     *
     * Idempotent per plugin instance (tracked via spl_object_id) — safe to
     * call multiple times. Kernel wires once per process in the constructor
     * after {@see boot()} populates $this->plugins and before any event
     * dispatch; tests may invoke it freely on isolated loader instances.
     *
     * Wiring runs OUTSIDE the {@see PluginLoaderCache} hit/miss branch
     * deliberately. The cache short-circuits manifest re-parsing on warm
     * boot — but a subscriber registered during plugin discovery would
     * silently disappear on warm boot if we honoured the cache here. The
     * cost is a cheap reflection-based `instanceof` check per plugin per
     * process; the gain is correct DI bindings and route registration on
     * every boot, cold or warm.
     */
    public function wireEventSubscribers(): void
    {
        foreach ($this->plugins as $plugin) {
            if (!$plugin instanceof EventSubscriberInterface) {
                continue;
            }
            $id = spl_object_id($plugin);
            if (isset($this->wiredSubscriberIds[$id])) {
                continue;
            }
            $this->wiredSubscriberIds[$id] = true;
            $this->dispatcher->addSubscriber($plugin);
        }
    }

}
