<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\NamedPlugin;

use Spora\Drivers\LLMDriverInterface;
use Spora\Plugins\AbstractPlugin;

final class NamedPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Named Plugin';
    }

    /**
     * @return array<string, class-string<LLMDriverInterface>>
     */
    public function drivers(): array
    {
        return ['named_driver' => NamedDriver::class];
    }
}
