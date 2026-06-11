<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ToolsPlugin;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;
use Spora\Tools\ToolInterface;
use Tests\Fixtures\TestTool;

/**
 * Test fixture plugin that contributes TestTool to the registry.
 * Used by ContainerDefinitionsTest to verify that the tool factory closures
 * (ToolConfigService, ToolController, tool_instances) merge and dedupe
 * the registered tool_classes with this plugin's contribution.
 */
final class Plugin implements PluginInterface
{
    /**
     * @return list<class-string<ToolInterface>>
     */
    public function tools(): array
    {
        return [
            TestTool::class,
        ];
    }

    public function getName(): string
    {
        return 'Tools Plugin';
    }

    public function autoload(): array
    {
        return [];
    }

    /**
     * @return array<string, class-string<\Spora\Drivers\LLMDriverInterface>>
     */
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

    /** @phpstan-return ?string */
    public function migrationsPath(): ?string
    {
        return null;
    }

    public function register(ContainerBuilder $builder): void
    {
        // No-op: this test fixture only contributes tool classes via tools().
    }
}
