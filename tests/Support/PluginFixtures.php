<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builders + cleanup for temp `<base>/plugins/<slug>/` trees used by
 * {@see \Spora\Core\Extension\PluginManager::list()} and the matching
 * `plugin:list` command test.
 *
 * Extracted from the two duplicated inline copies in
 * {@see \Tests\Unit\Extension\PluginManagerTest} and
 * {@see \Tests\Unit\Console\PluginListCommandTest} after the
 * filesystem-scanning rewrite of `PluginManager::list()`.
 */
final class PluginFixtures
{
    /**
     * Build a fresh `<base>/plugins/<slug>/{plugin.json, composer.json}` tree
     * and return the resolved base path.
     *
     * The base is created under `sys_get_temp_dir()` with the supplied
     * `tag` prefix (so concurrent test runs don't collide), then resolved
     * through `realpath()`. On macOS `/tmp` is a symlink to `/private/tmp`,
     * so the returned value is canonicalised — callers can compare plugin
     * paths verbatim against what `PluginManager::list()` produces.
     *
     * Each `plugin.json` is written with `slug` and a placeholder `class`
     * (`X\<slug>`); the manifest is never validated by `list()`, so the
     * shape is irrelevant to the tests — the file just needs to exist so
     * the glob in `PluginManager::list()` picks it up.
     *
     * @param  array<string, array<string, mixed>>  $plugins  slug => composer.json body
     */
    public static function buildTree(array $plugins, string $tag = 'spora-plugins'): string
    {
        $base = sys_get_temp_dir() . '/' . $tag . '-' . uniqid();
        mkdir($base . '/plugins', 0o755, true);

        foreach ($plugins as $slug => $composerBody) {
            $slugDir = $base . '/plugins/' . $slug;
            mkdir($slugDir, 0o755, true);
            file_put_contents(
                $slugDir . '/plugin.json',
                json_encode(['slug' => $slug, 'class' => 'X\\' . $slug], JSON_THROW_ON_ERROR),
            );
            file_put_contents(
                $slugDir . '/composer.json',
                json_encode($composerBody, JSON_THROW_ON_ERROR),
            );
        }

        $resolved = realpath($base);
        return $resolved === false ? $base : $resolved;
    }

    /**
     * Remove a tree previously built by {@see self::buildTree()}. Walks two
     * levels deep (plugin dir + its immediate files) — sufficient for the
     * flat fixtures the tests produce. Best-effort: any leftover files
     * survive silently rather than aborting the cleanup.
     */
    public static function removeTree(string $base): void
    {
        if (!is_dir($base . '/plugins')) {
            @rmdir($base);
            return;
        }

        foreach (glob($base . '/plugins/*') ?: [] as $slugDir) {
            foreach (glob($slugDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($slugDir);
        }
        @rmdir($base . '/plugins');
        @rmdir($base);
    }

    /**
     * Convenience wrapper: build a tree, run `$body($base)`, clean up.
     * Always cleans up, even when `$body` throws.
     *
     * @param  array<string, array<string, mixed>>  $plugins
     * @return mixed Whatever `$body` returns.
     */
    public static function withTree(array $plugins, callable $body, string $tag = 'spora-plugins'): mixed
    {
        $base = self::buildTree($plugins, $tag);
        try {
            return $body($base);
        } finally {
            self::removeTree($base);
        }
    }
}
