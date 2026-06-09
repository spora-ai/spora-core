<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_input', description: 'A stub input tool for testing')]
#[ToolOperation(name: 'default', description: 'Run the stub input', enabledByDefault: true, requiresApprovalByDefault: false)]
final class StubInputTool implements ToolInterface
{
    use HasOperations;

    public function __construct(
        private readonly string $resultContent = 'input_result',
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Run stub input';
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
