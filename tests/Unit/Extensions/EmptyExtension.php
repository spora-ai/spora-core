<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use Spora\Extensions\AbstractExtension;

/**
 * Concrete subclass with no overrides — used to verify that every hook
 * inherits its no-op default from AbstractExtension.
 */
final class EmptyExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'Empty';
    }
}
