<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SearchPlugin;

use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Search\SearchProviderInterface;

/**
 * Second provider of the same plugin, claiming the same `type::id` as
 * {@see StubSearchProvider} with a distinguishable label — so a test can tell
 * which one the registry kept.
 *
 * No constructor argument, unlike its sibling: the two together cover both
 * autowiring shapes a plugin provider can have.
 */
final class RivalSearchProvider implements SearchProviderInterface
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
            label: 'from-rival',
            href: '/apps/fixture-search/rival',
        )];
    }
}
