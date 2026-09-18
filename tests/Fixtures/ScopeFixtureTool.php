<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Fixture used by ToolSettingScopeTest / ToolControllerScopeTest to
 * exercise the new `scope` parameter on `#[ToolSetting]`. Mirrors the
 * render matrix in `HandoverTool::allowed_target_agents` (scope:
 * 'principal') alongside 'any' and 'agent' rows for full coverage.
 */
#[Tool(name: 'scope_test_tool', description: 'Fixture for the scope parameter')]
#[ToolSetting(key: 'global_only', label: 'Global Only', type: 'text')]
#[ToolSetting(key: 'principal_only', label: 'Principal Only', type: 'text', scope: 'principal')]
#[ToolSetting(key: 'agent_only', label: 'Agent Only', type: 'text', scope: 'agent')]
final class ScopeFixtureTool implements ToolInterface
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
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
