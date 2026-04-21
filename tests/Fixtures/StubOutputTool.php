<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_output', description: 'A stub output tool for testing')]
#[ToolOperation(name: 'default', description: 'Run the stub output', enabledByDefault: true, requiresApprovalByDefault: true)]
final class StubOutputTool implements ToolInterface
{
    use HasOperations;

    public function __construct(
        private readonly string $resultContent = 'output_result',
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Will perform stub output action.';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(true, $this->resultContent);
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}