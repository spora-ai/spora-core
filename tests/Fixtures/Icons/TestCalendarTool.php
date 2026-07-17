<?php

declare(strict_types=1);

namespace Tests\Fixtures\Icons;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Test fixture: declares a per-tool icon via #[Tool(icon: ...)] so the
 * ToolConfigNameResolver exercises the layer-1 path of the icon resolver.
 */
#[Tool(
    name: 'test_calendar',
    description: 'Calendar fixture for icon resolution tests.',
    displayName: 'Test Calendar',
    category: 'productivity',
    icon: 'calendar',
)]
final class TestCalendarTool implements ToolInterface
{
    use HasOperations;

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return new ToolResult(true, 'noop');
    }

    public function describeAction(array $arguments): string
    {
        return 'noop';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
