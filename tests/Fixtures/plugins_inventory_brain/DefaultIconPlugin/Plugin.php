<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\DefaultIconPlugin;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string
    {
        return 'Default Icon Plugin';
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
        return 0;
    }

    public function migrationsPath(): ?string
    {
        return null;
    }

    public function register(ContainerBuilder $builder): void {}
}
