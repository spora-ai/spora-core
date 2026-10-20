<?php

declare(strict_types=1);

namespace Spora\Build\Catalog;

/**
 * `plugin.json#description` for every installed plugin, so the plugins
 * screen is not the one surface that stays English on a German install.
 */
final class ManifestDescriptionHarvest implements Harvest
{
    public function __construct(
        private readonly SourceLocator $source,
    ) {}

    /**
     * @return list<array{message: string, locator: string}>
     */
    public function harvest(): array
    {
        $found = [];

        foreach ((array) glob($this->source->basePath() . '/plugins/*/plugin.json') as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $description = is_array($manifest) ? ($manifest['description'] ?? null) : null;

            if (is_string($description)) {
                $found[] = ['message' => $description, 'locator' => $this->source->relative((string) $manifestPath)];
            }
        }

        return $found;
    }
}
