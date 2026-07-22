<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Apps\AppInterface;

final class StubSampleApp implements AppInterface
{
    public function name(): string
    {
        return 'sample';
    }

    public function displayName(): string
    {
        return 'Sample';
    }

    public function description(): string
    {
        return 'Sample app used for fixture-only registrations';
    }

    public function icon(): string
    {
        return 'puzzle';
    }
}
