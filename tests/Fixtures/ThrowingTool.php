<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use RuntimeException;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'throwing_tool', description: 'A stub tool that always throws')]
#[ToolOperation(name: 'default', description: 'Run the throwing tool', enabledByDefault: true, requiresApprovalByDefault: false)]
final class ThrowingTool implements ToolInterface
{
    use HasOperations;

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Run throwing tool';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        throw new RuntimeException('Community plugin exploded!');
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
