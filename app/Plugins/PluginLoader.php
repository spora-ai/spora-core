<?php

declare(strict_types=1);

namespace Spora\Plugins;

use JsonException;
use Spora\Plugins\Exceptions\PluginLoadFailedException;

/**
 * Discovers and boots PluginInterface implementations from one or more plugin directories.
 *
 * Convention: each plugin lives in its own subdirectory and must ship a plugin.json
 * manifest declaring the entry-point class (and optionally a custom file path and
 * PSR-4 autoload mappings). Plugin autoload mappings are registered with the Composer
 * classloader so that the plugin's own classes are resolvable after boot.
 *
 * Caching:
 *   When $stampPath is provided, a composite hash of every discovered manifest is
 *   written to $stampPath after a successful boot. On subsequent boots with the same
 *   $stampPath and unchanged manifests, the loader short-circuits to a sidecar
 *   re-instantiation — no glob(), no file reads, no JSON decode. A sidecar JSON file
 *   sits next to the stamp and records the parsed manifests, slugs, classes, and
 *   plugin directories from the last successful boot.
 *
 * Discovery order:
 *   The directories passed in $pluginDirectories are scanned in the order given.
 *   If the same slug appears in multiple directories, the first one wins (later
 *   manifests with the same slug are silently skipped, matching the existing
 *   duplicate-slug guard).
 */
final class PluginLoader
{
    /** Stamp file suffix for the sidecar JSON cache. */
    private const SIDECAR_SUFFIX = '.cache.json';

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
     * validated for the required slug + class fields). Populated when a plugin
     * is loaded and replayed from the sidecar on cache hits.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $pluginManifests = [];

    private bool $booted = false;

    /**
     * @param list<string>  $pluginDirectories Absolute paths to scan for `<plugin>/plugin.json`.
     *                                        Non-existent directories are silently skipped.
     * @param ?string       $stampPath        Filesystem path to a stamp file. When set and
     *                                        current, the loader re-instantiates plugins
     *                                        from a sidecar JSON. When null, the loader
     *                                        always performs a full discovery (used in tests).
     */
    public function __construct(
        private readonly array $pluginDirectories,
        private readonly ?string $stampPath = null,
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

        // Resolve every manifest across every directory up front. The stamp hash
        // is computed over this snapshot so the cache hit/miss decision is based
        // on the actual on-disk state, not a partial scan.
        $discovered = $this->collectManifests();

        $hash = $this->computeStampHash($discovered);

        if ($this->stampPath !== null && $this->isStampCurrent($hash)) {
            $this->loadFromSidecar();
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

        if ($this->stampPath !== null) {
            $this->writeStampAndSidecar($hash);
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
     * Map of plugin slug => absolute plugin directory, for plugins that were loaded.
     *
     * @return array<string, string>
     */
    public function getPluginDirectories(): array
    {
        return $this->pluginDirs;
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
     * Scan every configured directory and return a deterministic list of
     * `path => contents` entries for every `<plugin>/plugin.json` found.
     *
     * @return array<string, array{path: string, contents: string}>
     */
    private function collectManifests(): array
    {
        $out = [];

        foreach ($this->pluginDirectories as $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            foreach (glob(rtrim($dir, '/') . '/*/plugin.json') ?: [] as $manifestFile) {
                $real = realpath($manifestFile);
                if ($real === false) {
                    continue;
                }
                $contents = @file_get_contents($real);
                if ($contents === false) {
                    continue;
                }
                $out[$real] = ['path' => $real, 'contents' => $contents];
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Build a deterministic sha256 hash over every discovered manifest.
     * Changes to any manifest (mtime or content) invalidate the stamp.
     *
     * @param array<string, array{path: string, contents: string}> $discovered
     */
    private function computeStampHash(array $discovered): string
    {
        $parts = [];
        foreach ($discovered as $entry) {
            $mtime = @filemtime($entry['path']);
            $parts[] = $entry['path'] . "\t" . ($mtime ?: 0) . "\t" . hash('sha256', $entry['contents']);
        }
        return hash('sha256', implode("\n", $parts));
    }

    private function isStampCurrent(string $hash): bool
    {
        if ($this->stampPath === null) {
            return false;
        }

        $existing = @file_get_contents($this->stampPath);
        return is_string($existing) && $existing === $hash;
    }

    /**
     * Re-instantiate every plugin recorded in the sidecar JSON, skipping
     * manifest re-parsing and re-discovery. Sets up PSR-4 autoload and
     * require_once's the plugin file exactly like a cold boot.
     */
    private function loadFromSidecar(): void
    {
        if ($this->stampPath === null) {
            return;
        }

        $sidecarPath = $this->sidecarPath();
        $raw = @file_get_contents($sidecarPath);
        if (!is_string($raw) || $raw === '') {
            // Corrupt or missing sidecar — fall back to full discovery.
            $this->fallbackToFullDiscovery();
            return;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fallbackToFullDiscovery();
            return;
        }

        if (!is_array($decoded) || !isset($decoded['plugins']) || !is_array($decoded['plugins'])) {
            $this->fallbackToFullDiscovery();
            return;
        }

        $classLoader = $this->findClassLoader();

        foreach ($decoded['plugins'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug  = $entry['slug']  ?? null;
            $dir   = $entry['directory'] ?? null;
            $manifest = $entry['manifest'] ?? null;
            if (!is_string($slug) || !is_string($dir) || !is_array($manifest)) {
                continue;
            }

            $this->registerManifestAutoload($manifest, $classLoader, $dir);

            $fileToRequire = isset($manifest['file']) && is_string($manifest['file'])
                ? $dir . '/' . ltrim($manifest['file'], '/')
                : $dir . '/Plugin.php';
            if (is_file($fileToRequire)) {
                require_once $fileToRequire;
            }

            $this->instantiatePlugin($slug, (string) $manifest['class'], $classLoader, $dir, $manifest);
        }
    }

    private function fallbackToFullDiscovery(): void
    {
        $classLoader = $this->findClassLoader();
        $discovered = $this->collectManifests();
        foreach ($discovered as $entry) {
            $this->loadPluginFromManifest($entry['path'], $entry['contents'], $classLoader);
        }
        // Rewrite the stamp + sidecar so the next boot can short-circuit again.
        $hash = $this->computeStampHash($discovered);
        if ($this->stampPath !== null) {
            $this->writeStampAndSidecar($hash);
        }
    }

    private function writeStampAndSidecar(string $hash): void
    {
        if ($this->stampPath === null) {
            return;
        }

        @file_put_contents($this->stampPath, $hash);

        $entries = [];
        foreach ($this->pluginManifests as $slug => $manifest) {
            $entries[] = [
                'slug'      => $slug,
                'class'     => $manifest['class'] ?? null,
                'directory' => $this->pluginDirs[$slug] ?? null,
                'manifest'  => $manifest,
            ];
        }

        $payload = json_encode(
            ['plugins' => $entries],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        @file_put_contents($this->sidecarPath(), $payload);
    }

    private function sidecarPath(): string
    {
        return $this->stampPath . self::SIDECAR_SUFFIX;
    }

    /**
     * @throws PluginLoadFailedException  When the manifest JSON is invalid or violates the schema contract.
     *                                     Not thrown when the declared class simply cannot be resolved at
     *                                     runtime (e.g. PSR-4 not yet registered) — that failure is silent.
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

        $this->registerManifestAutoload($manifest, $classLoader, $pluginDir);

        // Resolve the file to load: explicit "file" key in manifest, or conventional Plugin.php.
        // Skipped entirely when the class is already available via PSR-4 autoloading above.
        $fileToRequire = isset($manifest['file']) && is_string($manifest['file'])
            ? $pluginDir . '/' . ltrim($manifest['file'], '/')
            : $pluginDir . '/Plugin.php';

        if (is_file($fileToRequire)) {
            require_once $fileToRequire;
        }

        $this->instantiatePlugin($slug, $fqcn, $classLoader, $pluginDir, $manifest);
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

    /**
     * @param array<string, mixed> $manifest
     */
    private function instantiatePlugin(
        string $slug,
        string $fqcn,
        ?\Composer\Autoload\ClassLoader $classLoader,
        string $pluginDir,
        array $manifest,
    ): void {
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
