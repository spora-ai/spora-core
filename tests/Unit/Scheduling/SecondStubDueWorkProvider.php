<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use RuntimeException;
use Spora\Scheduling\DueWork;
use Spora\Scheduling\DueWorkProviderInterface;

/**
 * A second due-work provider, so a test can tell two apart.
 *
 * Core routes a unit of work back to the provider that named it, which means
 * two providers of the *same* class are one provider — the registry holds one
 * instance per class. A test that needs two distinct providers needs two
 * distinct classes.
 */
final class SecondStubDueWorkProvider implements DueWorkProviderInterface
{
    /** @var list<list<int>> One entry per `dueWork()` call. */
    public array $claims = [];

    /** @var list<DueWork> */
    public array $executed = [];

    /** @var list<DueWork> Handed back by `dueWork()`. */
    public array $work = [];

    public bool $throwOnClaim = false;

    public bool $throwOnExecute = false;

    /**
     * @param list<int> $principalIds
     * @return list<DueWork>
     */
    public function dueWork(array $principalIds): array
    {
        $this->claims[] = $principalIds;

        if ($this->throwOnClaim) {
            throw new RuntimeException('cannot report work');
        }

        return $this->work;
    }

    public function execute(DueWork $work): void
    {
        if ($this->throwOnExecute) {
            throw new RuntimeException('cannot execute work');
        }

        $this->executed[] = $work;
    }
}
