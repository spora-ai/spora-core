<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\NamedPlugin;

use DI\ContainerBuilder;
use Spora\Drivers\LLMDriverInterface;
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

    /**
     * @return array<string, class-string<LLMDriverInterface>>
     */
    public function drivers(): array
    {
        return ['named_driver' => NamedDriver::class];
    }

    public function recipePaths(): array
    {
        return [];
    }

    public function schemaVersion(): int
    {
        return 0;
    }

    /** @phpstan-return ?string */
    public function migrationsPath(): ?string
    {
        return null;
    }

    public function register(ContainerBuilder $builder): void {}
}
