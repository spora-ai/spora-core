<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\TestPlugin;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string
    {
        return 'Test Plugin';
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
        return ['test_driver' => self::class];
    }

    public function recipePaths(): array
    {
        return [dirname(__DIR__) . '/plugin_recipes'];
    }

    public function register(ContainerBuilder $builder): void {}
}
