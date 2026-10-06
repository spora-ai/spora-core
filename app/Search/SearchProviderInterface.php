<?php

declare(strict_types=1);

namespace Spora\Search;

/**
 * A source of palette search hits. Core ships none — a hit needs somewhere to
 * display it, and only the plugin that owns the content has that. A plugin ships
 * one for content the host knows nothing about.
 *
 * Deliberately narrower than {@see \Spora\Skills\SkillProviderInterface}: search
 * returns provenance-filtered summaries, so there is no unknown-vs-invisible
 * distinction to make and no file read to police.
 */
interface SearchProviderInterface
{
    /** Palette section bucket, e.g. `skill` or a plugin slug. */
    public function type(): string;

    /**
     * Hits for a query, most relevant first.
     *
     * MUST restrict itself to {@see SearchContext} and return `[]` when it is
     * empty — widening that is a cross-tenant read. Iterate
     * {@see SearchContext::principalIds()} rather than resolving scope itself.
     *
     * @return list<SearchHit>
     */
    public function search(string $query, SearchContext $context): array;
}
