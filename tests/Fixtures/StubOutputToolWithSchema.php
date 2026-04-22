<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_output_with_schema', description: 'A stub output tool with a required field in its schema')]
#[ToolOperation(name: 'default', description: 'Run the schema-validated output', enabledByDefault: true, requiresApprovalByDefault: true)]
final class StubOutputToolWithSchema implements ToolInterface
{
    use HasOperations;

    public function execute(array $arguments, int $agentId): ToolResult
    {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Will perform a schema-validated output action.';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(true, 'output_with_schema_result');
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'recipient' => ['type' => 'string', 'description' => 'Email recipient'],
            ],
            'required'   => ['recipient'],
        ];
    }
}
