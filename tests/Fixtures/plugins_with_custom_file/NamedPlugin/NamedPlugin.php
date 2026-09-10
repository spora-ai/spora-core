<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\NamedPlugin;

use Spora\Plugins\AbstractPlugin;

final class NamedPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Named Plugin';
    }
}
