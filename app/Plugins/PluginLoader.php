<?php

declare(strict_types=1);

namespace Spora\Plugins;

use DI\ContainerBuilder;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Plugins\Exceptions\PluginLoadFailedException;
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
 * manifests or calling each plugin's `register()` hook.
 *
 * Directories are scanned in the order given; if the same slug appears in more
 * than one, the first one wins.
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

    /**
     * @param list<string>  $pluginDirectories Absolute paths to scan for `<plugin>/plugin.json`.
     *                                        Non-existent directories are silently skipped.
     * @param ?string       $stampPath        Filesystem path to a stamp file. When set and
     *                                        current, the loader re-instantiates plugins
     *                                        from a sidecar JSON. When null, the loader
     *                                        always performs a full discovery (used in tests).
     */
    public function __construct(
        array $pluginDirectories,
        ?string $stampPath = null,
    ) {
        $this->cache = new PluginLoaderCache($pluginDirectories, $stampPath);
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
            $this->restoreFromSidecar();
            return;
        }

        $classLoader = $this->findClassLoader();

        foreach ($discovered as $entry) {
            $this->loadPluginFromManifest(
                $entry['path'],
                $entry['contents'],
                $classLoader,
            );
        }

        $this->cache->write($discovered, $this->buildSidecarEntries());
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
     * All LLM driver class FQCNs contributed by loaded plugins, keyed by provider name.
     *
     * @return array<string, class-string>
     */
    public function drivers(): array
    {
        $drivers = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->drivers() as $provider => $class) {
                $drivers[$provider] = $class;
            }
        }

        return $drivers;
    }

    /**
     * All recipe directory paths contributed by loaded plugins.
     *
     * @return string[]
     */
    public function recipePaths(): array
    {
        $paths = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->recipePaths() as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
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
     * Map of plugin slug => absolute plugin directory, for plugins that were loaded.
     *
     * @return array<string, string>
     */
    public function getPluginDirectories(): array
    {
        return $this->pluginDirs;
    }

    /**
     * Returns each loaded plugin's `composer.json` `suggest` field, keyed
     * by the plugin's slug. Composer's `suggest` is reused as the
     * "companion plugins" surface — no new field is invented in
     * `plugin.json`.
     *
     * Result shape: `{ slug => { 'package-name' => 'description', ... } }`.
     * Plugins without a `composer.json` or without a `suggest` field
     * contribute nothing — the SPA hides the section for them.
     *
     * Cached per load — the `composer.json` is read once when the slug
     * is first asked for.
     *
     * @return array<string, array<string, string>>
     */
    public function suggestedPackages(): array
    {
        if ($this->pluginManifests === []) {
            return [];
        }

        $result = [];
        foreach ($this->pluginDirs as $slug => $dir) {
            $suggest = $this->readComposerSuggest($dir);
            if ($suggest !== []) {
                $result[$slug] = $suggest;
            }
        }

        return $result;
    }

    /**
     * Reads `composer.json` from the plugin directory and returns its
     * `suggest` field. Errors (missing file, malformed JSON, non-object
     * `suggest`) yield an empty array — failures are never surfaced
     * because the suggestion list is purely informational.
     *
     * @return array<string, string>
     */
    private function readComposerSuggest(string $pluginDir): array
    {
        $path = rtrim($pluginDir, '/') . '/composer.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $suggest = $decoded['suggest'] ?? null;
        if (!is_array($suggest)) {
            return [];
        }

        $clean = [];
        foreach ($suggest as $package => $description) {
            if (!is_string($package) || $package === '') {
                continue;
            }
            if (!is_string($description)) {
                continue;
            }
            $clean[$package] = $description;
        }

        return $clean;
    }

    /**
     * The raw parsed manifest for a given slug, or null if the slug is not loaded.
     * Useful for surfacing manifest-only metadata (e.g. `description`) that is not
     * part of PluginInterface.
     *
     * @return array<string, mixed>|null
     */
    public function getPluginManifest(string $slug): ?array
    {
        return $this->pluginManifests[$slug] ?? null;
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
     * Invoke each loaded plugin's `register(ContainerBuilder)` hook. Called by
     * Kernel AFTER appLoader->load() (so App::register() ran first) and BEFORE
     * $builder->build() (so plugin DI bindings are part of the container graph).
     *
     * Plugin-throws are caught and logged (or stderr'd) so a single misbehaving
     * plugin does not break boot.
     */
    public function registerPlugins(ContainerBuilder $builder): void
    {
        foreach ($this->plugins as $slug => $plugin) {
            try {
                $plugin->register($builder);
            } catch (Throwable $e) {
                error_log(sprintf(
                    '[spora] plugin %s register() failed: %s',
                    $slug,
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * Invoke each loaded plugin's `routes(MiddlewareRouteCollector)` hook.
     * Called per-request by Kernel::buildRouter() after the project's App routes
     * have been registered — plugin routes can override or extend those.
     */
    public function registerRoutes(MiddlewareRouteCollector $routes): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->routes($routes);
        }
    }

    private bool $extensionsBooted = false;

    /**
     * Invoke each loaded plugin's `boot()` hook. Called per-request by
     * Kernel::handle() after the project's App has booted. Idempotent — repeat
     * calls within the same process are no-ops.
     */
    public function bootExtensions(): void
    {
        if ($this->extensionsBooted) {
            return;
        }
        $this->extensionsBooted = true;

        foreach ($this->plugins as $plugin) {
            $plugin->boot();
        }
    }

    /**
     * Re-instantiate every plugin recorded in the sidecar JSON. Falls back to
     * a full discovery if the sidecar is missing or corrupt.
     */
    private function restoreFromSidecar(): void
    {
        $entries = $this->cache->read();

        if ($entries === null) {
            $this->fallbackToFullDiscovery();
            return;
        }

        $classLoader = $this->findClassLoader();

        foreach ($entries as $entry) {
            $this->restorePluginFromSidecarEntry($entry, $classLoader);
        }
    }

    /**
     * @param mixed $entry
     */
    private function restorePluginFromSidecarEntry(mixed $entry, ?\Composer\Autoload\ClassLoader $classLoader): void
    {
        if (!is_array($entry)) {
            return;
        }

        $slug     = $entry['slug']      ?? null;
        $dir      = $entry['directory'] ?? null;
        $manifest = $entry['manifest']  ?? null;

        if (!is_string($slug) || !is_string($dir) || !is_array($manifest)) {
            return;
        }

        $class = $manifest['class'] ?? null;
        if (!is_string($class) || $class === '') {
            // Sidecar entry is partially corrupt — surface to the caller so the
            // boot path falls back to a full cold discovery rather than failing
            // inside instantiatePlugin with a cryptic message.
            throw new PluginLoadFailedException(
                "Sidecar entry for plugin '{$slug}' is missing the 'class' field.",
            );
        }

        $this->registerManifestAutoload($manifest, $classLoader, $dir);

        $this->instantiatePlugin($slug, $class, $classLoader, $dir, $manifest, $dir . '/plugin.json');
    }

    /**
     * Sidecar is unusable (missing, undecodable, schema-mismatch) — perform a
     * full cold discovery and persist a fresh cache so the next boot can
     * short-circuit again.
     */
    private function fallbackToFullDiscovery(): void
    {
        $discovered = $this->cache->collectManifests();

        $classLoader = $this->findClassLoader();
        foreach ($discovered as $entry) {
            $this->loadPluginFromManifest($entry['path'], $entry['contents'], $classLoader);
        }

        $this->cache->write($discovered, $this->buildSidecarEntries());
    }

    /**
     * @return list<array{slug: string, class: ?string, directory: ?string, manifest: array<string, mixed>}>
     */
    private function buildSidecarEntries(): array
    {
        $entries = [];
        foreach ($this->pluginManifests as $slug => $manifest) {
            $entries[] = [
                'slug'      => $slug,
                'class'     => $manifest['class'] ?? null,
                'directory' => $this->pluginDirs[$slug] ?? null,
                'manifest'  => $manifest,
            ];
        }
        return $entries;
    }

    /**
     * @throws PluginLoadFailedException
     */
    private function loadPluginFromManifest(
        string $manifestFile,
        string $contents,
        ?\Composer\Autoload\ClassLoader $classLoader,
    ): void {
        $manifest = $this->parseAndValidateManifest($contents, $manifestFile);
        $slug     = $manifest['slug'];
        $fqcn     = $manifest['class'];
        $pluginDir = dirname($manifestFile);

        // PSR-4 mappings must register before instantiatePlugin() resolves the class.
        $this->registerManifestAutoload($manifest, $classLoader, $pluginDir);

        $this->instantiatePlugin($slug, $fqcn, $classLoader, $pluginDir, $manifest, $manifestFile);
    }

    /**
     * Decodes and structurally validates the manifest. Returns the full manifest array
     * (preserving autoload and other optional fields) so callers can read them after
     * the required slug/class fields have been verified.
     *
     * @return array<string, mixed>
     *
     * @throws PluginLoadFailedException
     */
    private function parseAndValidateManifest(string $raw, string $manifestFile): array
    {
        $manifest = json_decode($raw, true);

        if (!is_array($manifest)) {
            throw new PluginLoadFailedException(
                "Plugin manifest '{$manifestFile}' contains invalid JSON.",
            );
        }

        if (!isset($manifest['slug']) || !is_string($manifest['slug'])) {
            throw new PluginLoadFailedException(
                "Plugin manifest '{$manifestFile}' is missing the required 'slug' field. " .
                "See plugin.schema.json for the full manifest contract.",
            );
        }

        $slug = $manifest['slug'];

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new PluginLoadFailedException(
                "Plugin manifest '{$manifestFile}' has an invalid slug '{$slug}'. " .
                "Slugs must be lowercase alphanumeric and may contain hyphens or underscores " .
                "(e.g. 'my-plugin').",
            );
        }

        if (!isset($manifest['class']) || !is_string($manifest['class'])) {
            throw new PluginLoadFailedException(
                "Plugin manifest '{$manifestFile}' (slug: '{$slug}') is missing the required 'class' field. " .
                "See plugin.schema.json for the full manifest contract.",
            );
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function registerManifestAutoload(array $manifest, ?\Composer\Autoload\ClassLoader $classLoader, string $pluginDir): void
    {
        if ($classLoader !== null && isset($manifest['autoload']['psr-4']) && is_array($manifest['autoload']['psr-4'])) {
            foreach ($manifest['autoload']['psr-4'] as $namespace => $relativePath) {
                $classLoader->addPsr4((string) $namespace, $pluginDir . '/' . ltrim((string) $relativePath, '/'));
            }
        }

        // For plugins with their own Composer dependency tree.
        if (isset($manifest['autoload']['files']) && is_array($manifest['autoload']['files'])) {
            foreach ($manifest['autoload']['files'] as $relFile) {
                $abs = $pluginDir . '/' . ltrim((string) $relFile, '/');
                if (is_file($abs)) {
                    require_once $abs;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @throws PluginLoadFailedException When the class fails `is_a(..., true)` against
     *                                   {@see PluginInterface} — either unresolvable via PSR-4
     *                                   or not implementing the interface.
     */
    private function instantiatePlugin(
        string $slug,
        string $fqcn,
        ?\Composer\Autoload\ClassLoader $classLoader,
        string $pluginDir,
        array $manifest,
        string $manifestFile = '',
    ): void {
        if (!is_a($fqcn, PluginInterface::class, true)) {
            throw new PluginLoadFailedException(sprintf(
                "Plugin manifest '%s' declares class '%s' but the class is not autoloadable "
                . "or does not implement %s. Check that the manifest's autoload.psr-4 entry "
                . "points to the right directory, and that the package's composer.json declares "
                . "a matching PSR-4 mapping.",
                $manifestFile !== '' ? $manifestFile : $pluginDir . '/plugin.json',
                $fqcn,
                PluginInterface::class,
            ));
        }

        if (isset($this->plugins[$slug])) {
            return;
        }

        foreach ($this->plugins as $existing) {
            if (get_class($existing) === $fqcn) {
                return;
            }
        }

        /** @var PluginInterface $plugin */
        $plugin = new $fqcn();

        if ($classLoader !== null) {
            foreach ($plugin->autoload() as $namespace => $path) {
                $classLoader->addPsr4($namespace, $path);
            }
        }

        $this->plugins[$slug] = $plugin;
        $this->pluginDirs[$slug] = $pluginDir;
        $this->pluginManifests[$slug] = $manifest;
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
