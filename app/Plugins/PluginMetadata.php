<?php

declare(strict_types=1);

namespace Spora\Plugins;

/**
 * On-disk metadata of the loaded plugins: the directory each one was booted
 * from, its parsed `plugin.json`, and the `composer.json` fields the plugin
 * catalogue and the agent-template importer read.
 *
 * The maps arrive by reference; {@see PluginLoader} explains why.
 */
final class PluginMetadata
{
    /**
     * @var array<string, string>
     */
    private array $pluginDirs;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $pluginManifests;

    /**
     * @param array<string, string>               $pluginDirs
     * @param array<string, array<string, mixed>> $pluginManifests
     */
    public function __construct(array &$pluginDirs, array &$pluginManifests)
    {
        $this->pluginDirs = &$pluginDirs;
        $this->pluginManifests = &$pluginManifests;
    }

    /**
     * Resolve a plugin slug to its Composer package name (e.g.
     * `spora-ai/spora-plugin-media-archive`). Reads the plugin's
     * `composer.json#name` from the on-disk plugin directory.
     *
     * The slug alone is a filesystem identifier — it isn't a Packagist
     * identifier, so a template exporter that emits slugs leaves the
     * importer unable to resolve the requirement. The package name is
     * what `composer require <name>` and Packagist's search API both
     * understand.
     *
     * Returns null when the slug isn't loaded, the plugin directory has
     * no readable `composer.json`, or the `name` field is missing —
     * callers should treat null as "skip; the operator will see the
     * missing plugin at import time".
     */
    public function getComposerNameForSlug(string $slug): ?string
    {
        $dir = $this->pluginDirs[$slug] ?? null;
        if ($dir === null) {
            return null;
        }
        $decoded = $this->readComposerJson($dir);
        if (!is_array($decoded)) {
            return null;
        }
        $name = $decoded['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }


    /**
     * Inverse of {@see getComposerNameForSlug()}: resolve a Composer
     * `vendor/name` package string back to the on-disk slug of the
     * loaded plugin that ships it.
     *
     * Walks every loaded plugin's directory and reads its
     * `composer.json#name`. Used by the agent-template importer to
     * decide whether an `required_plugins` entry from an exported
     * template (vendor/name) is satisfied by the current instance —
     * there is no slug-keyed map to consult directly.
     *
     * Returns null when no loaded plugin declares that package name,
     * a `composer.json` is unreadable, or the `name` field is missing.
     * Callers should treat null as "PLUGIN_MISSING".
     */
    public function getSlugForPackageName(string $package): ?string
    {
        if ($package === '') {
            return null;
        }
        foreach ($this->pluginDirs as $slug => $dir) {
            $decoded = $this->readComposerJson($dir);
            if (!is_array($decoded)) {
                continue;
            }
            $name = $decoded['name'] ?? null;
            if (is_string($name) && $name === $package) {
                return $slug;
            }
        }
        return null;
    }


    /** @return array<string, mixed>|null */
    private function readComposerJson(string $dir): ?array
    {
        $path = $dir . '/composer.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
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
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        $suggest = is_array($decoded) ? ($decoded['suggest'] ?? null) : null;

        return is_array($suggest) ? $this->filterSuggestEntries($suggest) : [];
    }


    /**
     * @param array<mixed, mixed> $suggest
     * @return array<string, string>
     */
    private function filterSuggestEntries(array $suggest): array
    {
        $clean = [];
        foreach ($suggest as $package => $description) {
            if (!is_string($package) || $package === '' || !is_string($description)) {
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
}
