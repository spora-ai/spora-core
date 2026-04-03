<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\InputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(
    name: 'spy_agent_input',
    description: 'Returns the injected agentId.',
)]
final class SpyAgentIdInputTool implements InputToolInterface
{
    public function execute(array $arguments, int $agentId): ToolResult
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
