<?php

declare(strict_types=1);

namespace Spora\Plugins;

use Spora\Plugins\Exceptions\PluginLoadFailedException;

/**
 * Turns a discovered `plugin.json` into an instantiated {@see PluginInterface}
 * and records it on the loader's slug-keyed maps. Owns both halves of the boot
 * path — cold discovery and the sidecar restore {@see PluginLoaderCache}
 * short-circuits to — so warm and cold boots apply the same validation,
 * autoload registration, and duplicate rules.
 *
 * The maps arrive by reference; {@see PluginLoader} explains why.
 */
final class PluginDiscovery
{
    private readonly PluginLoaderCache $cache;

    /**
     * @var array<string, PluginInterface>
     */
    private array $plugins;

    /**
     * @var array<string, string>
     */
    private array $pluginDirs;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $pluginManifests;

    /**
     * @param array<string, PluginInterface>      $plugins
     * @param array<string, string>               $pluginDirs
     * @param array<string, array<string, mixed>> $pluginManifests
     */
    public function __construct(
        PluginLoaderCache $cache,
        array &$plugins,
        array &$pluginDirs,
        array &$pluginManifests,
    ) {
        $this->cache = $cache;
        $this->plugins = &$plugins;
        $this->pluginDirs = &$pluginDirs;
        $this->pluginManifests = &$pluginManifests;
    }

    /**
     * Instantiate every collected manifest. Cold half of {@see PluginLoader::boot()};
     * the sidecar write stays with the caller, which already holds `$discovered`.
     *
     * @param array<string, array{path: string, contents: string}> $discovered
     */
    public function loadDiscovered(array $discovered): void
    {
        $classLoader = $this->findClassLoader();

        foreach ($discovered as $entry) {
            $this->loadPluginFromManifest(
                $entry['path'],
                $entry['contents'],
                $classLoader,
            );
        }
    }

    /**
     * Re-instantiate every plugin recorded in the sidecar JSON. Falls back to
     * a full discovery if the sidecar is missing or corrupt.
     */
    public function restoreFromSidecar(): void
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

        $this->instantiatePlugin($slug, $class, $dir, $manifest, $dir . '/plugin.json');
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
    public function buildSidecarEntries(): array
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

        $this->instantiatePlugin($slug, $fqcn, $pluginDir, $manifest, $manifestFile);
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
