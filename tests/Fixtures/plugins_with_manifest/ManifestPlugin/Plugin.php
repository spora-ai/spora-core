<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ManifestPlugin;

use Spora\Plugins\AbstractPlugin;

final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Manifest Plugin';
    }
}
