<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_auto_output', description: 'A stub auto-approved output tool for testing')]
#[ToolOperation(name: 'default', description: 'Run the stub auto-approved output', enabledByDefault: true, requiresApprovalByDefault: false)]
final class StubAutoApproveOutputTool implements ToolInterface
{
    use HasOperations;

    public function execute(array $arguments, int $agentId): ToolResult
    {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Will auto-approve and execute.';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(true, 'auto_output_result');
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}