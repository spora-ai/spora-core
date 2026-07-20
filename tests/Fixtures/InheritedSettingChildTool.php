<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'inherited_setting_child', description: 'Fixture for inheritance')]
#[ToolSetting(key: 'child_only', label: 'Child Only', type: 'text', default: 'local')]
#[ToolSetting(key: 'shared_key', label: 'Child Shared', type: 'text', default: 'child-default')]
final class InheritedSettingChildTool extends InheritedSettingBaseTool implements ToolInterface
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return 'ok';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
