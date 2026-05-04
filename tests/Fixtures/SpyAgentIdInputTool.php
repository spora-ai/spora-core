<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(
    name: 'spy_agent_input',
    description: 'Returns the injected agentId.',
)]
#[ToolOperation(name: 'default', description: 'Run the spy agent input', enabledByDefault: true, requiresApprovalByDefault: false)]
final class SpyAgentIdInputTool implements ToolInterface
{
    use HasOperations;

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Run spy agent input';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(true, "Agent ID is: {$agentId}");
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];
    }
}
