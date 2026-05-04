<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'stub_failing', description: 'Always returns a failed ToolResult for testing')]
#[ToolOperation(name: 'default', description: 'Run the failing tool', enabledByDefault: true, requiresApprovalByDefault: false)]
final class StubFailingTool implements ToolInterface
{
    use HasOperations;

    public function __construct(
        private readonly string $errorMessage = 'Stub tool failure',
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Run failing tool';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(false, $this->errorMessage);
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
