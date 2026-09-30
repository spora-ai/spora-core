<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\ToolInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * A `class_exists`-positive tool that does **not** use the `HasOperations`
 * trait. Every tool in `app/Tools/` does, so this fixture is the only way to
 * reach the branch where the two readers of
 * `agent_tool_operation_overrides` deliberately disagree: the Orchestrator
 * throws a `ToolContractException`, the resolver returns `true`.
 */
final class StubPlainToolWithoutOperations implements ToolInterface
{
    public function name(): string
    {
        return 'stub_plain';
    }

    public function description(): string
    {
        return 'A stub tool with no HasOperations trait.';
    }

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return 'Will run the plain stub tool.';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
