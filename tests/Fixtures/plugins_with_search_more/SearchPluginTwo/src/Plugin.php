<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SearchPluginTwo;

use Spora\Plugins\AbstractPlugin;
use Tests\Fixtures\Plugins\SearchPlugin\StubSearchProvider;

/**
 * Second fixture plugin, contributing its own provider *and* repeating a class
 * the first fixture plugin already named.
 *
 * The repeat is what the dedupe assertion needs: two plugins, one class, one
 * registry entry. Naming a class owned by another plugin is safe here because
 * both fixtures are always loaded together in the tests that read this list —
 * the loader registers every manifest's PSR-4 before the container resolves a
 * single provider.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Search Plugin Two';
    }

    /**
     * @return list<class-string<\Spora\Search\SearchProviderInterface>>
     */
    public function searchProviders(): array
    {
        return [LaterSearchProvider::class, StubSearchProvider::class];
    }
}
