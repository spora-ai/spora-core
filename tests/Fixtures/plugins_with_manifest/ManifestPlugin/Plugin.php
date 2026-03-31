<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ManifestPlugin;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string
    {
        return 'Manifest Plugin';
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
        return ['manifest_driver' => self::class];
    }

    public function recipePaths(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void {}
}
