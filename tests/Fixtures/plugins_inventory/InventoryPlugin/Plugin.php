<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\InventoryPlugin;

use Spora\Plugins\AbstractPlugin;
use Spora\Tools\ReadUrlTool;

final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Inventory Plugin';
    }

    public function tools(): array
    {
        return [ReadUrlTool::class];
    }

    public function drivers(): array
    {
        return ['inventory_driver' => InventoryDriver::class];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }
}
