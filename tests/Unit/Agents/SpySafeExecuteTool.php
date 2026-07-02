<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Spy tool that records the (userId, taskId) pair it was invoked with
 * so tests can assert that safeExecute passes $taskId through to the
 * tool instance.
 */
#[Tool(name: 'spy_safe_execute', description: 'Records execute() args')]
#[ToolOperation(name: 'default', description: 'noop', enabledByDefault: true, requiresApprovalByDefault: false)]
final class SpySafeExecuteTool implements ToolInterface
{
    use HasOperations;

    public static ?int $lastTaskId = null;
    public static ?int $lastUserId = null;

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        self::$lastUserId = $userId;
        self::$lastTaskId = $taskId;
        return new ToolResult(true, 'ok');
    }

    public function describeAction(array $arguments): string
    {
        return 'noop';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
}
