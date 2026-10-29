<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\DueWorkPlugin;

use Spora\Scheduling\DueWork;
use Spora\Scheduling\DueWorkProviderInterface;

/**
 * A second provider from the same plugin, contributed only when the plugin
 * says so — the "a plugin ships a provider without touching its manifest"
 * case.
 */
final class SecondDueWorkProvider implements DueWorkProviderInterface
{
    /** @var list<list<int>> */
    public array $claims = [];

    /**
     * @param list<int> $principalIds
     * @return list<DueWork>
     */
    public function dueWork(array $principalIds): array
    {
        $this->claims[] = $principalIds;

        return [];
    }

    public function execute(DueWork $work): void {}
}
