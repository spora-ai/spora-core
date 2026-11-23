<?php

/*
 * Helpers for the app/I18n unit tests.
 *
 * Lives here rather than inside one test file for the reason
 * CrossFileTestHelpers.php documents: Pest's parallel runner loads each
 * test file in isolation, so a function defined in TranslatorTest.php is
 * not visible to CatalogLoaderTest.php when the two run in different
 * workers.
 */

declare(strict_types=1);

if (!function_exists('makeLocaleDir')) {
    /**
     * Write one `<locale>.json` per entry under a fresh temp directory.
     *
     * Every i18n test points `CatalogLoader` at a tree built this way —
     * never at the framework's own `locales/`, because an assertion about
     * a German value would then break on any copy edit in an unrelated
     * file.
     *
     * The directory is fresh and owner-only: its name carries a random
     * suffix, so a directory left behind by another run (or by another
     * user) is never adopted, and nothing can be planted inside it.
     *
     * @param array<string, array<string, string>> $catalogues locale => message map
     */
    function makeLocaleDir(array $catalogues, ?string $name = null): string
    {
        $dir = sys_get_temp_dir() . '/spora-i18n-' . ($name !== null ? $name . '-' : '') . bin2hex(random_bytes(8));

        if (!mkdir($dir, 0o700, true)) {
            throw new RuntimeException("Could not create the locale fixture directory {$dir}.");
        }

        // Registered before the catalogues are written: a half-built
        // directory has to be cleaned up too.
        $GLOBALS['__locale_fixtures'][] = $dir;

        foreach ($catalogues as $locale => $messages) {
            $path = $dir . '/' . $locale . '.json';

            // 'x' fails when the path exists already — a symlink included —
            // so a fixture is never written through one.
            $handle = fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException("Could not create the locale fixture {$path}.");
            }
            fwrite($handle, (string) json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fclose($handle);
        }

        return $dir;
    }
}

if (!function_exists('localeFixtureRegistry')) {
    /**
     * The fixture directories this process created.
     *
     * The system temp directory is shared by every parallel Pest worker on
     * the machine, so a directory is only ours to remove when this process
     * is the one that made it.
     *
     * @return list<string>
     */
    function localeFixtureRegistry(): array
    {
        return $GLOBALS['__locale_fixtures'] ?? [];
    }
}

if (!function_exists('removeLocaleFixtures')) {
    /**
     * Remove only the fixtures this process created — another worker's are
     * not ours to delete.
     */
    function removeLocaleFixtures(): void
    {
        foreach (localeFixtureRegistry() as $dir) {
            removeLocaleDir($dir);
        }
        $GLOBALS['__locale_fixtures'] = [];
    }
}

if (!function_exists('removeLocaleDir')) {
    /**
     * Remove a fixture directory and the files in it.
     *
     * An entry that is not a file is left alone: the shared temp directory
     * holds trees that are not ours to dismantle — the plugin fixture keeps
     * a copy of the plugin in a subdirectory — and unlinking a directory
     * warns instead of removing it.
     */
    function removeLocaleDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $complete = true;

        foreach ((array) glob($dir . '/*') as $file) {
            if (!is_file((string) $file)) {
                $complete = false;

                continue;
            }

            unlink((string) $file);
        }

        // A directory that still holds a subdirectory cannot go, and rmdir()
        // warns when it tries.
        if ($complete) {
            rmdir($dir);
        }
    }
}
