<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'test_tool', description: 'A test tool')]
#[ToolSetting(key: 'api_key', label: 'API Key', type: 'password', scope: 'agent')]
#[ToolSetting(key: 'max_results', label: 'Max Results', type: 'text', scope: 'global')]
#[ToolOperation(name: 'default', description: 'Run the test tool', enabledByDefault: true, requiresApprovalByDefault: false)]
final class TestTool implements ToolInterface
{
    use HasOperations;

    public function execute(array $arguments, int $agentId): ToolResult
    {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Run test tool';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(true, 'ok');
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];
    }
}