<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * A throwaway tool used to test #[ToolSetting] reflection in
 * AgentToolInstanceResolver without touching the real CalculatorTool fixture.
 * Attributes are placed at the class level because getToolPasswordKeys
 * reads class-level ToolSetting attributes.
 */
#[Tool(name: 'pwtool', description: 'fake', displayName: 'PW', category: 'test')]
#[ToolSetting(key: 'secret', type: 'password', label: 'Secret', required: false)]
#[ToolSetting(key: 'public', type: 'string', label: 'Public', required: false)]
final class CollaboratorTestPasswordTool extends AbstractTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return new ToolResult(true, '');
    }

    public function describeAction(array $arguments): string
    {
        return '';
    }
}
