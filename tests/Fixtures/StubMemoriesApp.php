<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Apps\AppInterface;

final class StubMemoriesApp implements AppInterface
{
    public function name(): string
    {
        return 'memories';
    }

    public function displayName(): string
    {
        return 'Memories';
    }

    public function description(): string
    {
        return 'Global and per-agent memory store';
    }

    public function icon(): string
    {
        return 'brain';
    }
}
