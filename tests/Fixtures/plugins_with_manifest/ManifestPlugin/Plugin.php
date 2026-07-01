<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ManifestPlugin;

use Spora\Drivers\LLMDriverInterface;
use Spora\Plugins\AbstractPlugin;

final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Manifest Plugin';
    }

    /**
     * @return array<string, class-string<LLMDriverInterface>>
     */
    public function drivers(): array
    {
        return ['manifest_driver' => ManifestDriver::class];
    }
}
