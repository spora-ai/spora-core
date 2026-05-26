<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\MigratingPlugin;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string
    {
        return 'Migrating Plugin';
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
        return [];
    }

    public function recipePaths(): array
    {
        return [];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/migrations';
    }

    public function register(ContainerBuilder $builder): void {}
}
