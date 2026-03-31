<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\OutputTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\OutputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_output_with_schema', description: 'A stub output tool with a required field in its schema')]
#[OutputTool(requiresApproval: true)]
final class StubOutputToolWithSchema implements OutputToolInterface
{
    public function execute(array $arguments): ToolResult
    {
        return new ToolResult(true, 'output_with_schema_result');
    }

    public function describeAction(array $arguments): string
    {
        return 'Will perform a schema-validated output action.';
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
