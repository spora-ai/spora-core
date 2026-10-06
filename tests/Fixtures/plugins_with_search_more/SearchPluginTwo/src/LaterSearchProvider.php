<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SearchPluginTwo;

use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Search\SearchProviderInterface;
use Tests\Fixtures\Plugins\SearchPlugin\StubSearchProvider;

/**
 * This plugin's own provider, claiming the same `type::id` as the two providers
 * in the other fixture plugin.
 *
 * It is therefore the loser whenever both plugins are loaded — plugin
 * discovery is alphabetical, so the first plugin loads first and its provider
 * keeps the key. That is the precedence rule in its entirety: nothing in core
 * competes, so load order decides.
 */
final class LaterSearchProvider implements SearchProviderInterface
{
    public function type(): string
    {
        return StubSearchProvider::TYPE;
    }

    /** @return list<SearchHit> */
    public function search(string $query, SearchContext $context): array
    {
        return [new SearchHit(
            type: StubSearchProvider::TYPE,
            id: StubSearchProvider::CLAIMED_ID,
            label: 'from-later',
            href: '/apps/fixture-search/later',
        )];
    }
}
