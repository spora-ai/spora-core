<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\InputTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\InputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_input', description: 'A stub input tool for testing')]
#[InputTool]
final class StubInputTool implements InputToolInterface
{
    public function __construct(
        private readonly string $resultContent = 'input_result',
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(true, $this->resultContent);
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
