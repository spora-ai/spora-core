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
        $GLOBALS['__spora_locale_fixtures'][] = $dir;

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

if (!function_exists('removeLocaleFixtures')) {
    /**
     * Remove only the fixtures this process created — another worker's are
     * not ours to delete.
     *
     * The system temp directory is shared by every parallel Pest worker on
     * the machine, so a directory is only ours to remove when this process
     * is the one that made it.
     */
    function removeLocaleFixtures(): void
    {
        foreach ($GLOBALS['__spora_locale_fixtures'] ?? [] as $dir) {
            removeLocaleDir($dir);
        }
        $GLOBALS['__spora_locale_fixtures'] = [];
    }
}

if (!function_exists('removeLocaleDir')) {
    /**
     * Remove a fixture directory and the files in it.
     *
     * A directory that still holds a subdirectory is left behind, and
     * silently so: the registry only ever holds the flat trees this
     * process created, so a subdirectory means the tree is not ours to
     * dismantle. The leftover is the price of not touching what we did
     * not create, and nothing sweeps it up afterwards.
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
