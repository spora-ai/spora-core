<?php

declare(strict_types=1);

namespace Spora\Plugins;

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
    /** @var PluginInterface[] */
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

    /** @return PluginInterface[] */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    private function loadPluginFromManifest(string $manifestFile, ?\Composer\Autoload\ClassLoader $classLoader): void
    {
        $raw = file_get_contents($manifestFile);

        if ($raw === false) {
            return;
        }

        $manifest = json_decode($raw, true);

        if (!is_array($manifest) || !isset($manifest['class']) || !is_string($manifest['class'])) {
            return;
        }

        $fqcn      = $manifest['class'];
        $pluginDir = dirname($manifestFile);

        // Register PSR-4 autoload mappings declared in the manifest before require_once.
        if ($classLoader !== null && isset($manifest['autoload']['psr-4']) && is_array($manifest['autoload']['psr-4'])) {
            foreach ($manifest['autoload']['psr-4'] as $namespace => $relativePath) {
                $classLoader->addPsr4((string) $namespace, $pluginDir . '/' . ltrim((string) $relativePath, '/'));
            }
        }

        // Resolve the file to load: explicit "file" key in manifest, or conventional Plugin.php.
        // Skipped entirely when the class is already available via PSR-4 autoloading above.
        $fileToRequire = isset($manifest['file']) && is_string($manifest['file'])
            ? $pluginDir . '/' . ltrim($manifest['file'], '/')
            : $pluginDir . '/Plugin.php';

        if (is_file($fileToRequire)) {
            require_once $fileToRequire;
        }

        $this->instantiatePlugin($fqcn, $classLoader);
    }

    private function instantiatePlugin(string $fqcn, ?\Composer\Autoload\ClassLoader $classLoader): void
    {
        if (!class_exists($fqcn, false) || !is_a($fqcn, PluginInterface::class, true)) {
            return;
        }

        // Skip if already loaded.
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

        $this->plugins[] = $plugin;
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
