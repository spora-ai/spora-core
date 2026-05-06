<?php

declare(strict_types=1);

namespace Spora\Apps;

final class MemoriesApp implements AppInterface
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
        return 'Persistent memory storage for agents and users';
    }

    public function icon(): string
    {
        return 'brain';
    }
}
