<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ToolsPlugin;

use Spora\Plugins\AbstractPlugin;
use Spora\Tools\ToolInterface;
use Tests\Fixtures\TestTool;

/**
 * Test fixture plugin that contributes TestTool to the registry.
 * Used by ContainerDefinitionsTest to verify that the tool factory closures
 * (ToolConfigService, ToolController, tool_instances) merge and dedupe
 * the registered tool_classes with this plugin's contribution.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Tools Plugin';
    }

    /**
     * @return list<class-string<ToolInterface>>
     */
    public function tools(): array
    {
        return [
            TestTool::class,
        ];
    }
}
