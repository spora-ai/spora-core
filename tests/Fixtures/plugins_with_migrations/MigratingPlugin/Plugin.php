<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\MigratingPlugin;

use Spora\Plugins\AbstractPlugin;

final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Migrating Plugin';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/migrations';
    }
}
