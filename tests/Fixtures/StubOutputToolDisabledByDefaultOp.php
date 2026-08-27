<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Same shape as {@see StubOutputTool} but with `enabledByDefault: false` on the
 * `default` operation. Exercises the fall-through branch in
 * {@see \Spora\Agents\Orchestrator::isOperationEnabled()} where no
 * `AgentToolOperationOverride` row exists and the resolution falls through
 * to the tool's `isEnabledByDefault()` method.
 */
#[Tool(name: 'stub_output_disabled_default', description: 'A stub output tool whose default op is disabled by default')]
#[ToolOperation(
    name: 'default',
    description: 'Run the stub output (disabled by default)',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
final class StubOutputToolDisabledByDefaultOp implements ToolInterface
{
    use HasOperations;

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return $this->run($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        return 'Will perform a default-disabled stub output action.';
    }

    public function run(array $arguments, int $agentId): ToolResult
    {
        return new ToolResult(true, 'disabled_default_op_output_result');
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
