<?php

declare(strict_types=1);

namespace Spora\I18n;

use Spora\Plugins\PluginInterface;

/**
 * The catalogue directories the loaded plugins contribute, in load order.
 *
 * The directories are listed without their slug: the catalogue loader
 * merges them itself, reading core's catalogue first, so a core entry
 * wins over a plugin entry with the same key.
 *
 * This lives in the i18n layer rather than on the plugin loader because
 * the loader's job is to find plugins, not to know which of them ship
 * translations.
 */
final class PluginCatalogDirectories
{
    /**
     * @param array<string, PluginInterface> $plugins
     * @return list<string>
     */
    public static function from(array $plugins): array
    {
        $paths = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->translationsPaths() as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
