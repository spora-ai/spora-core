<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builders + cleanup for temp `<base>/plugins/<slug>/` trees used by the
 * `PluginManager::list()` tests.
 */
final class PluginFixtures
{
    /**
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

        // realpath collapses macOS /tmp → /private/tmp so callers can
        // compare plugin paths verbatim against what list() returns.
        $resolved = realpath($base);
        return $resolved === false ? $base : $resolved;
    }

    /** Best-effort cleanup of a tree from {@see self::buildTree()}. */
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
     * Build a tree, run `$body($base)`, clean up — even on throw.
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
