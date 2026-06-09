<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\InventoryPlugin;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;
use Spora\Tools\ReadUrlTool;

final class Plugin implements PluginInterface
{
    public function getName(): string
    {
        return 'Inventory Plugin';
    }

    public function autoload(): array
    {
        return [];
    }

    public function tools(): array
    {
        return [ReadUrlTool::class];
    }

    public function drivers(): array
    {
        return ['inventory_driver' => InventoryDriver::class];
    }

    public function recipePaths(): array
    {
        return [];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }

    public function register(ContainerBuilder $builder): void {}
}
