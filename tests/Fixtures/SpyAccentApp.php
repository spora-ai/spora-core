<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Apps\AppInterface;

/**
 * Test fixture whose `accent()` returns a value outside the known enum.
 * Lets the AppsController tests prove that an unknown PHP-supplied
 * token is dropped in favour of the plugin manifest's `accent` field —
 * the same fallback posture as the unknown-icon-name → `puzzle`
 * resolver, but for the accent wire shape.
 */
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
