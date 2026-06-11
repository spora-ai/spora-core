<?php

declare(strict_types=1);

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

afterEach(function (): void {
    SpySafeExecuteTool::$lastTaskId = null;
    SpySafeExecuteTool::$lastUserId = null;
});

test('safeExecute passes $taskId to the tool instance as the 4th argument', function (): void {
    $orchestrator = new Spora\Agents\Orchestrator(
        Mockery::mock(Spora\Drivers\DriverFactory::class),
        new Spora\Agents\OrchestratorConfig(
            toolInstances: [new SpySafeExecuteTool()],
            logger: new Psr\Log\NullLogger(),
        ),
    );

    $result = $orchestrator->safeExecute(
        new SpySafeExecuteTool(),
        [],
        agentId: 7,
        taskId: 1234,
        userId: 99,
    );

    expect($result->success)->toBeTrue();
    expect(SpySafeExecuteTool::$lastTaskId)->toBe(1234);
    expect(SpySafeExecuteTool::$lastUserId)->toBe(99);
});

test('safeExecute tolerates null $userId for backward compat', function (): void {
    $orchestrator = new Spora\Agents\Orchestrator(
        Mockery::mock(Spora\Drivers\DriverFactory::class),
        new Spora\Agents\OrchestratorConfig(
            logger: new Psr\Log\NullLogger(),
        ),
    );

    $orchestrator->safeExecute(
        new SpySafeExecuteTool(),
        [],
        agentId: 1,
        taskId: 1,
        userId: null,
    );

    expect(SpySafeExecuteTool::$lastUserId)->toBeNull();
    expect(SpySafeExecuteTool::$lastTaskId)->toBe(1);
});
