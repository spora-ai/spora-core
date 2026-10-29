<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use RuntimeException;
use Spora\Scheduling\DueWork;
use Spora\Scheduling\DueWorkProviderInterface;

/**
 * A due-work provider that records what it was asked and what it was told to
 * execute.
 *
 * The recording is the point: the dispatch tests assert on the principal ids
 * the provider was handed, which is the contract core owns.
 */
final class StubDueWorkProvider implements DueWorkProviderInterface
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
