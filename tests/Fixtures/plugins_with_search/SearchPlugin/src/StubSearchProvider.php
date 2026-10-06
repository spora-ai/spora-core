<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\SearchPlugin;

use Psr\Log\LoggerInterface;
use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Search\SearchProviderInterface;

/**
 * Provider whose constructor takes an argument, so the wiring test can prove
 * the registry resolves each class *through the container*: PHP-DI has to
 * autowire `LoggerInterface` for this to exist at all, which a registry that
 * did `new $class()` could not do.
 *
 * Claims the same `type::id` as its siblings in this fixture — the collision a
 * precedence rule has to resolve, and the one a plugin author can get wrong.
 */
final class StubSearchProvider implements SearchProviderInterface
{
    public const TYPE = 'fixture-search';

    public const CLAIMED_ID = 'invoice';

    public int $calls = 0;

    public function __construct(
        public readonly LoggerInterface $logger,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    /** @return list<SearchHit> */
    public function search(string $query, SearchContext $context): array
    {
        $this->calls++;

        return [new SearchHit(
            type: self::TYPE,
            id: self::CLAIMED_ID,
            label: 'from-stub',
            href: '/apps/fixture-search/invoice',
        )];
    }
}
