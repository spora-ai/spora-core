<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'fixture_abstract_tool', description: 'AbstractTool unit test fixture')]
#[ToolOperation(name: 'run', description: 'Run')]
#[ToolOperation(name: 'stop', description: 'Stop')]
#[ToolParameter(name: 'q', type: 'string', description: 'Query', required: true)]
final class AbstractToolTestFixture extends AbstractTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return 'fixture';
    }
}
