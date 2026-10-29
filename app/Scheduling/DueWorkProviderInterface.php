<?php

declare(strict_types=1);

namespace Spora\Scheduling;

/**
 * A plugin that has work of its own to run on a cadence.
 *
 * The scheduler in core fires prompts at agents; it knows nothing about
 * publishing. A provider says "I have work that is due", and the worker
 * hands it the principals it may act for.
 */
interface DueWorkProviderInterface
{
    /**
     * Report the work that is due for the given principals.
     *
     * The provider owns its rows: core hands over the principals and takes
     * the result, and never touches the provider's tables.
     *
     * Two workers on the same install must not both run the same unit of
     * work. How the provider guarantees that is its own business: one that
     * takes the work out of the running does it in a single atomic
     * transition of its own rows, and one that only selects the candidates
     * here and claims them in {@see self::execute()} gives the same
     * guarantee — as long as the claim there is atomic.
     *
     * A unit for a principal outside `$principalIds` is discarded and never
     * executed, so a provider must not claim a unit before it knows the unit
     * is in scope: a claimed unit that is discarded is lost work.
     *
     * @param list<int> $principalIds principals the caller may act for
     * @return list<DueWork>
     */
    public function dueWork(array $principalIds): array;

    /** Execute one unit of work. */
    public function execute(DueWork $work): void;
}
