<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use Spora\Extensions\AbstractExtension;

/**
 * Subclass of AbstractExtension that doesn't implement AppInterface — to
 * prove AppLoader's acceptance check uses SporaExtensionInterface, not
 * AppInterface, so apps don't have to `implements AppInterface` explicitly.
 * Non-final for the same reason as SpyApp.
 */
class PlainApp extends AbstractExtension
{
    public function getName(): string
    {
        return 'Plain';
    }
}
