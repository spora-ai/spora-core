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

if (!function_exists('removeLocaleDir')) {
    function removeLocaleDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir($dir);
    }
}
