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
     * @param array<string, array<string, string>> $catalogues locale => message map
     */
    function makeLocaleDir(array $catalogues, ?string $name = null): string
    {
        $dir = sys_get_temp_dir() . '/spora-i18n-' . ($name ?? bin2hex(random_bytes(6)));
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        foreach ($catalogues as $locale => $messages) {
            file_put_contents(
                $dir . '/' . $locale . '.json',
                (string) json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
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
