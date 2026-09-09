<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use Spora\Extensions\AbstractExtension;
use Spora\Extensions\AppInterface;

/**
 * App used to verify AppLoader still discovers the concrete App class. Non-final
 * because AppLoader's discovery creates a runtime subclass via
 * `class App extends SpyApp {}` written to a file on disk.
 */
class SpyApp extends AbstractExtension implements AppInterface
{
    public function getName(): string
    {
        return 'Spy';
    }
}
