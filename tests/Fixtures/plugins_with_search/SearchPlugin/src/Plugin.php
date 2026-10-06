<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SearchPlugin;

use Spora\Plugins\AbstractPlugin;

/**
 * Fixture plugin contributing two {@see \Spora\Search\SearchProviderInterface}
 * implementations, in this order.
 *
 * PSR-4 is declared in `plugin.json` rather than spora-core's `composer.json`,
 * so the fixture is self-contained exactly as a shipped plugin is: the loader
 * registers the mapping at boot, before the container resolves anything.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Search Plugin';
    }

    /**
     * @return list<class-string<\Spora\Search\SearchProviderInterface>>
     */
    public function searchProviders(): array
    {
        return [StubSearchProvider::class, RivalSearchProvider::class];
    }
}
