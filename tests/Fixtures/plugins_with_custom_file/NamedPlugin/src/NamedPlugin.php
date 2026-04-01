<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\NamedPlugin;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;

final class NamedPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'Named Plugin';
    }

    public function autoload(): array
    {
        return [];
    }

    public function tools(): array
    {
        return [];
    }

    public function drivers(): array
    {
        return ['named_driver' => self::class];
    }

    public function recipePaths(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void {}
}
