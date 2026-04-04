<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\InputTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\InputToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_failing', description: 'Always returns a failed ToolResult for testing')]
#[InputTool]
final class StubFailingTool implements InputToolInterface
{
    public function __construct(
        private readonly string $errorMessage = 'Stub tool failure',
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(false, $this->errorMessage);
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
