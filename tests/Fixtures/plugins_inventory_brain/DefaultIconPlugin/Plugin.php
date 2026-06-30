<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\DefaultIconPlugin;

use Spora\Plugins\AbstractPlugin;

final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Default Icon Plugin';
    }
}
