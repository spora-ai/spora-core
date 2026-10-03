<?php

declare(strict_types=1);

namespace Spora\Search;

/**
 * Who is searching, as a resolved set rather than a nullable id: the interesting
 * case is *across* principals — an operator looks for a skill whether it is
 * theirs or a group's, and one id cannot express that. Empty means the caller can
 * see nothing, which makes scoping structural for a provider that iterates these.
 */
final readonly class SearchContext
{
    /** @var list<int> */
    private array $principalIds;

    /** @param list<int> $principalIds Principals the caller may see. */
    public function __construct(array $principalIds = [])
    {
        // Ids arrive from the database as strings, and the strict in_array() in
        // isVisible() only trusts them once they are ints.
        $this->principalIds = array_map(intval(...), $principalIds);
    }

    /** @return list<int> */
    public function principalIds(): array
    {
        return $this->principalIds;
    }

    public function isVisible(int $principalId): bool
    {
        return in_array($principalId, $this->principalIds, true);
    }
}
