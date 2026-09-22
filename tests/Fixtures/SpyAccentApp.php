<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Apps\AppInterface;

/** `accent()` returns a value outside the known enum. */
final class SpyAccentApp implements AppInterface
{
    public function name(): string
    {
        return 'accent-spy';
    }

    public function displayName(): string
    {
        return 'Accent Spy';
    }

    public function description(): string
    {
        return 'Fixture whose accent() returns a non-enum token.';
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    public function accent(): string
    {
        return 'neon-pink';
    }
}
