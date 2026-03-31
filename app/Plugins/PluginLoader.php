<?php

declare(strict_types=1);

namespace Spora\Plugins;

/**
 * Discovers and boots PluginInterface implementations from the plugins/ directory.
 *
 * Convention: each plugin lives in its own subdirectory and must contain a class
 * that implements PluginInterface. The loader finds all PHP files one level deep,
 * requires them, and instantiates any class that implements PluginInterface.
 *
 * Plugin autoload mappings are registered with the Composer classloader so that
 * the plugin's own classes are resolvable after boot.
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

        foreach (glob($this->pluginsDirectory . '/*/Plugin.php') ?: [] as $file) {
            $pluginDir = dirname($file);
            $this->loadPlugin($pluginDir, $file, $classLoader);
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

    /**
     * Load a plugin from its directory.
     *
     * Resolution order:
     *   1. `plugin.json` manifest — reads the FQCN from `"class"` and registers any
     *      `"autoload"."psr-4"` mappings declared there. Fast: `json_decode` beats
     *      PHP tokenisation every time.
     *   2. PHP token parsing of `Plugin.php` — legacy fallback for plugins that do
     *      not ship a manifest.
     */
    private function loadPlugin(string $pluginDir, string $pluginFile, ?\Composer\Autoload\ClassLoader $classLoader): void
    {
        $manifestFile = $pluginDir . '/plugin.json';

        if (is_file($manifestFile)) {
            $this->loadPluginFromManifest($manifestFile, $pluginFile, $classLoader);
        } else {
            $this->loadPluginFile($pluginFile, $classLoader);
        }
    }

    private function loadPluginFromManifest(string $manifestFile, string $pluginFile, ?\Composer\Autoload\ClassLoader $classLoader): void
    {
        $raw = file_get_contents($manifestFile);

        if ($raw === false) {
            return;
        }

        $manifest = json_decode($raw, true);

        if (!is_array($manifest) || !isset($manifest['class']) || !is_string($manifest['class'])) {
            return;
        }

        $fqcn = $manifest['class'];

        // Register PSR-4 autoload mappings declared in the manifest before require_once.
        if ($classLoader !== null && isset($manifest['autoload']['psr-4']) && is_array($manifest['autoload']['psr-4'])) {
            $pluginDir = dirname($manifestFile);
            foreach ($manifest['autoload']['psr-4'] as $namespace => $relativePath) {
                $classLoader->addPsr4((string) $namespace, $pluginDir . '/' . ltrim((string) $relativePath, '/'));
            }
        }

        require_once $pluginFile;

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

    private function loadPluginFile(string $file, ?\Composer\Autoload\ClassLoader $classLoader): void
    {
        $fqcn = $this->extractFqcn($file);

        if ($fqcn === null) {
            return;
        }

        require_once $file;

        $this->instantiatePlugin($fqcn, $classLoader);
    }

    /**
     * Extract the fully-qualified class name from a PHP file by parsing its tokens.
     * Returns null if no class declaration is found.
     */
    private function extractFqcn(string $file): ?string
    {
        $source = file_get_contents($file);

        if ($source === false) {
            return null;
        }

        $tokens    = token_get_all($source);
        $count     = count($tokens);
        $namespace = '';
        $i         = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                $i++;
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $i++;
                $ns = '';
                while ($i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{') {
                    if (is_array($tokens[$i])) {
                        $ns .= $tokens[$i][1];
                    }
                    $i++;
                }
                $namespace = trim($ns);
                continue;
            }

            if ($token[0] === T_CLASS) {
                // Skip past whitespace to the class name token.
                $i++;
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                    $className = $tokens[$i][1];
                    return $namespace !== '' ? $namespace . '\\' . $className : $className;
                }
            }

            $i++;
        }

        return null;
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
