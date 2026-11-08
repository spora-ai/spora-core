<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * A tool with two operations: `safe` is enabled, `dangerous` is disabled.
 *
 * The tool records the operation it dispatched to, so a test can tell
 * which one ran.
 */
#[Tool(name: 'stub_two_operation', description: 'A stub tool with an enabled and a disabled operation')]
#[ToolOperation(
    name: 'safe',
    description: 'The enabled operation',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'dangerous',
    description: 'The disabled operation',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
final class StubTwoOperationTool implements ToolInterface
{
    use HasOperations;

    /** The operation the tool last dispatched to. */
    public ?string $dispatchedOperation = null;

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Will perform the stub operation.';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        $this->dispatchedOperation = $this->getOperationName($arguments);

        return new ToolResult(true, 'two_operation_' . $this->dispatchedOperation);
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => ['type' => 'string'],
            ],
            'required'   => [],
        ];
    }
}
