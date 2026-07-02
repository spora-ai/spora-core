<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Mockery;
use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;

afterEach(function (): void {
    SpySafeExecuteTool::$lastTaskId = null;
    SpySafeExecuteTool::$lastUserId = null;
});

test('safeExecute passes $taskId to the tool instance as the 4th argument', function (): void {
    $orchestrator = new Orchestrator(
        Mockery::mock(DriverFactory::class),
        new OrchestratorConfig(
            toolInstances: [new SpySafeExecuteTool()],
            logger: new NullLogger(),
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
    $orchestrator = new Orchestrator(
        Mockery::mock(DriverFactory::class),
        new OrchestratorConfig(
            logger: new NullLogger(),
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
