<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\Traits\HasParameterSchema;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'serializer_fixture', description: 'Serializer test fixture')]
#[ToolOperation(name: 'run', description: 'Run')]
#[ToolOperation(name: 'stop', description: 'Stop')]
#[ToolParameter(name: 'q', type: 'string', description: 'Query', required: true)]
final class ToolCallSerializerFixtureTool implements ToolInterface
{
    use HasOperations;
    use HasParameterSchema;

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return 'fixture';
    }
}
