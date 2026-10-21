<?php

declare(strict_types=1);

namespace Spora\I18n;

use Psr\Log\LoggerInterface;
use Spora\Core\Paths;

/**
 * Reads `locales/<locale>.json` from the framework plus every plugin that
 * declares a `locales/` directory, and merges them into one flat map per
 * locale.
 *
 * The catalogue key is the English source string, so a plugin and core
 * collide as soon as both say "API key". Core wins and the plugin entry is
 * dropped: a third-party package must not be able to reword a core string,
 * and a duplicate must never fail the boot.
 *
 * Missing files are not an error. A plugin that ships no catalogue falls
 * through to core's, and core's identity map falls through to the English
 * literal in {@see Translator} — which is why a fresh install needs no
 * translations at all.
 */
final class CatalogLoader
{
    private const CATALOGUE_SUFFIX = '.json';

    /**
     * Merged catalogues, keyed by locale. A request translates the same
     * handful of strings repeatedly, and the merge is the only part that
     * does file I/O.
     *
     * @var array<string, array<string, string>>
     */
    private array $cache = [];

    /**
     * @param list<string>         $pluginLocaleDirs Absolute paths to plugin `locales/` directories, from {@see \Spora\I18n\PluginCatalogDirectories::from()}.
     * @param ?string              $coreLocaleDir    Overrides the framework's own `locales/` directory. Tests point this at a fixture tree; nothing else should.
     * @param ?LoggerInterface     $logger           Receives the collision warning. Null in a context with no logger, where the merge still succeeds silently.
     */
    public function __construct(
        private readonly array $pluginLocaleDirs = [],
        private readonly ?string $coreLocaleDir = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function has(string $locale): bool
    {
        $locale = trim($locale);
        if ($locale === '') {
            return false;
        }

        foreach ($this->catalogueFiles($locale) as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The merged catalogue for one locale. Core entries win over plugin
     * entries with the same key, and the first plugin wins over the ones
     * after it. A dropped key is logged rather than swallowed, because the
     * only way to see it is the log.
     *
     * @return array<string, string>
     */
    public function messagesFor(string $locale): array
    {
        $locale = trim($locale);
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $core = $this->read($this->coreCatalogueFile($locale));
        $plugins = [];
        foreach ($this->pluginLocaleDirs as $dir) {
            foreach ($this->read($dir . '/' . $locale . self::CATALOGUE_SUFFIX) as $key => $value) {
                if (isset($core[$key]) || isset($plugins[$key])) {
                    $this->logger?->warning(
                        'Translation catalogue collision on key; the core catalogue wins and the plugin entry is ignored.',
                        ['locale' => $locale, 'key' => $key, 'plugin_dir' => $dir],
                    );
                    continue;
                }
                $plugins[$key] = $value;
            }
        }

        return $this->cache[$locale] = $core + $plugins;
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        $locales = [];

        foreach ($this->directories() as $dir) {
            foreach ((array) glob($dir . '/*' . self::CATALOGUE_SUFFIX) as $file) {
                $locales[] = basename((string) $file, self::CATALOGUE_SUFFIX);
            }
        }

        $locales = array_values(array_unique(array_filter($locales)));
        sort($locales);

        return $locales;
    }

    /**
     * @return list<string>
     */
    private function directories(): array
    {
        return [$this->coreLocaleDir ?? (new Paths(dirname(__DIR__, 2)))->locales(), ...$this->pluginLocaleDirs];
    }

    /**
     * @return list<string>
     */
    private function catalogueFiles(string $locale): array
    {
        return array_map(
            static fn(string $dir): string => $dir . '/' . $locale . self::CATALOGUE_SUFFIX,
            $this->directories(),
        );
    }

    private function coreCatalogueFile(string $locale): string
    {
        return ($this->coreLocaleDir ?? (new Paths(dirname(__DIR__, 2)))->locales()) . '/' . $locale . self::CATALOGUE_SUFFIX;
    }

    /**
     * A JSON object of string→string, or `[]` for anything else — a missing
     * file, malformed JSON, or a nested catalogue. A plugin whose catalogue
     * cannot be read renders English rather than failing the request.
     *
     * @return array<string, string>
     */
    private function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            return [];
        }

        $messages = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $messages[$key] = $value;
            }
        }

        return $messages;
    }
}
