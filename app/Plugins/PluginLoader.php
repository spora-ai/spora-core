<?php

declare(strict_types=1);

namespace Spora\Plugins;

use Spora\Plugins\Exceptions\PluginLoadFailedException;

/**
 * Discovers and boots PluginInterface implementations from the plugins/ directory.
 *
 * Convention: each plugin lives in its own subdirectory and must ship a plugin.json
 * manifest declaring the entry-point class (and optionally a custom file path and
 * PSR-4 autoload mappings). Plugin autoload mappings are registered with the Composer
 * classloader so that the plugin's own classes are resolvable after boot.
 */
final class PluginLoader
{
    /**
     * Loaded plugins, keyed by their manifest slug.
     *
     * @var array<string, PluginInterface>
     */
    private array $plugins = [];

    private bool $booted = false;

    public function __construct(
        private readonly string $pluginsDirectory,
    ) {}

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

        if (!is_dir($this->pluginsDirectory)) {
            return;
        }

        $classLoader = $this->findClassLoader();

        foreach (glob($this->pluginsDirectory . '/*/plugin.json') ?: [] as $manifestFile) {
            $this->loadPluginFromManifest($manifestFile, $classLoader);
        }
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
     * All loaded plugins, indexed by their manifest slug.
     *
     * @return array<string, PluginInterface>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
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
     * @throws PluginLoadFailedException  When the manifest JSON is invalid or violates the schema contract.
     *                                     Not thrown when the declared class simply cannot be resolved at
     *                                     runtime (e.g. PSR-4 not yet registered) — that failure is silent.
     */
    private function loadPluginFromManifest(string $manifestFile, ?\Composer\Autoload\ClassLoader $classLoader): void
    {
        $raw = file_get_contents($manifestFile);

        if ($raw === false) {
            return; // unreadable file — filesystem issue, not a manifest error
        }

        $manifest = $this->parseAndValidateManifest($raw, $manifestFile);
        $slug     = $manifest['slug'];
        $fqcn     = $manifest['class'];
        $pluginDir = dirname($manifestFile);

        $this->registerManifestAutoload($manifest, $classLoader, $pluginDir);

        // Resolve the file to load: explicit "file" key in manifest, or conventional Plugin.php.
        // Skipped entirely when the class is already available via PSR-4 autoloading above.
        $fileToRequire = isset($manifest['file']) && is_string($manifest['file'])
            ? $pluginDir . '/' . ltrim($manifest['file'], '/')
            : $pluginDir . '/Plugin.php';

        if (is_file($fileToRequire)) {
            require_once $fileToRequire;
        }

        $this->instantiatePlugin($slug, $fqcn, $classLoader);
    }

    /**
     * Decodes and structurally validates the manifest. Returns the full manifest array
     * (preserving autoload, file, and other optional fields) so callers can read them
     * after the required slug/class fields have been verified.
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
        // Register PSR-4 autoload mappings declared in the manifest before require_once.
        if ($classLoader !== null && isset($manifest['autoload']['psr-4']) && is_array($manifest['autoload']['psr-4'])) {
            foreach ($manifest['autoload']['psr-4'] as $namespace => $relativePath) {
                $classLoader->addPsr4((string) $namespace, $pluginDir . '/' . ltrim((string) $relativePath, '/'));
            }
        }

        // Require bootstrap files (e.g. vendor/autoload.php for plugins with their own Composer deps).
        if (isset($manifest['autoload']['files']) && is_array($manifest['autoload']['files'])) {
            foreach ($manifest['autoload']['files'] as $relFile) {
                $abs = $pluginDir . '/' . ltrim((string) $relFile, '/');
                if (is_file($abs)) {
                    require_once $abs;
                }
            }
        }
    }

    private function instantiatePlugin(string $slug, string $fqcn, ?\Composer\Autoload\ClassLoader $classLoader): void
    {
        if (!class_exists($fqcn, false) || !is_a($fqcn, PluginInterface::class, true)) {
            return;
        }

        // Skip if slug or class is already loaded.
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
