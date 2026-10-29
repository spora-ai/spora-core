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
     * Claim the work that is due for the given principals.
     *
     * The implementation owns the atomic claim — core hands over the
     * principals and takes the result; it never touches the provider's rows.
     * Two workers on the same install must not both come away with the same
     * unit of work, so the claim has to be a single atomic transition of the
     * provider's own rows.
     *
     * @param list<int> $principalIds principals the caller may act for
     * @return list<DueWork>
     */
    public function dueWork(array $principalIds): array;

    /** Execute one claimed unit of work. */
    public function execute(DueWork $work): void;
}
