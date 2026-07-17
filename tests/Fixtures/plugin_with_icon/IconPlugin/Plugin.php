<?php

declare(strict_types=1);

namespace Tests\Fixtures\PluginWithIcon\IconPlugin;

use Spora\Plugins\AbstractPlugin;
use Spora\Tools\ToolInterface;
use Tests\Fixtures\Icons\TestCalendarTool;
use Tests\Fixtures\TestTool;

/**
 * Test fixture plugin that contributes two tools:
 *
 *   - TestTool          — has NO #[Tool(icon: ...)]; falls through to layer 2
 *                         via this plugin's plugin.json "icon": "mail" field.
 *   - TestCalendarTool  — has #[Tool(icon: 'calendar')]; layer 1 must win
 *                         over this plugin's "icon": "mail".
 *
 * Used by ToolIconResolverTest to verify both the layer-2 fallback and the
 * layer-1 > layer-2 precedence rule.
 */
final class Plugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Icon Plugin';
    }

    /**
     * @return list<class-string<ToolInterface>>
     */
    public function tools(): array
    {
        return [
            TestTool::class,
            TestCalendarTool::class,
        ];
    }
}