<?php

declare(strict_types=1);

namespace Spora\Scheduling;

/**
 * A unit of work together with the provider that reported it.
 *
 * The provider travels with the unit because the class name in
 * {@see DueWork::$provider} is written by the provider and can be wrong:
 * routing on it would hand the unit to a provider that never claimed it.
 */
final readonly class ReportedDueWork
{
    public function __construct(
        public DueWorkProviderInterface $provider,
        public DueWork $work,
    ) {}
}
