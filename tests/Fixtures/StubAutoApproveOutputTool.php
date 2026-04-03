<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\OutputTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\OutputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_auto_output', description: 'A stub auto-approved output tool for testing')]
#[OutputTool(requiresApproval: false)]
final class StubAutoApproveOutputTool implements OutputToolInterface
{
    public function execute(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(true, 'auto_output_result');
    }

    public function describeAction(array $arguments): string
    {
        return 'Will auto-approve and execute.';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
