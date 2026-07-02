<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use Spora\Plugins\AbstractPlugin;
use Spora\Tools\ToolInterface;
use Tests\Fixtures\TestTool;

/**
 * Trivial subclass used to verify the AbstractPlugin defaults. The class name
 * deliberately ends in "Plugin" so {@see AbstractPlugin::getName()} can prove
 * it strips the suffix.
 */
final class DemoPlugin extends AbstractPlugin
{
    /** @return array<class-string<ToolInterface>> */
    public function tools(): array
    {
        return [TestTool::class];
    }
}
