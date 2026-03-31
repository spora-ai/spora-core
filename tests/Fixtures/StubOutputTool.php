<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\OutputTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\OutputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_output', description: 'A stub output tool for testing')]
#[OutputTool(requiresApproval: true)]
final class StubOutputTool implements OutputToolInterface
{
    public function __construct(
        private readonly string $resultContent = 'output_result',
    ) {}

    public function execute(array $arguments): ToolResult
    {
        return new ToolResult(true, $this->resultContent);
    }

    public function describeAction(array $arguments): string
    {
        return 'Will perform stub output action.';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
