<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ToolInterface;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Spy tool that records the `(context, taskId)` pair it was invoked with, so
 * tests can assert what `safeExecute()` hands a tool instance.
 *
 * `$lastUserId` is derived from the context rather than taken from an
 * argument, mirroring what a migrated plugin does. With the legacy `?int
 * $userId` parameter gone there is no other channel to observe.
 */
#[Tool(name: 'spy_safe_execute', description: 'Records execute() args')]
#[ToolOperation(name: 'default', description: 'noop', enabledByDefault: true, requiresApprovalByDefault: false)]
final class SpySafeExecuteTool implements ToolInterface
{
    use HasOperations;

    public static ?PrincipalContext $lastContext = null;
    public static ?int $lastTaskId = null;
    public static ?int $lastUserId = null;

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        self::$lastContext = $context;
        self::$lastUserId  = $context?->ownerUserId;
        self::$lastTaskId  = $taskId;

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
