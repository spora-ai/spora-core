<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Mockery;
use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Models\Principal;

afterEach(function (): void {
    SpySafeExecuteTool::$lastContext = null;
    SpySafeExecuteTool::$lastTaskId  = null;
    SpySafeExecuteTool::$lastUserId  = null;
});

test('safeExecute hands the tool a context resolving the calling agent owner', function (): void {
    // A real user row: `principals.user_id` is a FK, so the owner cannot be
    // materialised without one.
    $ownerUserId = bootAuth(bootAuthLayer(), 'safe-execute@example.com', 'Password1!');
    $agentId     = $this->makeAgentWithPrincipal([], $ownerUserId);
    $principalId = $this->principalIdFor($ownerUserId);

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
        agentId: $agentId,
        taskId: 1234,
    );

    expect($result->success)->toBeTrue();

    // The context is the only ownership channel a tool gets — there is no
    // separate user-id argument to fall back on.
    expect(SpySafeExecuteTool::$lastContext)->not->toBeNull()
        ->and(SpySafeExecuteTool::$lastContext->principalId)->toBe($principalId)
        ->and(SpySafeExecuteTool::$lastContext->type)->toBe(Principal::TYPE_USER)
        ->and(SpySafeExecuteTool::$lastContext->ownerUserId)->toBe($ownerUserId)
        ->and(SpySafeExecuteTool::$lastUserId)->toBe($ownerUserId)
        ->and(SpySafeExecuteTool::$lastTaskId)->toBe(1234);
});

test('safeExecute yields a null owner when the calling agent does not exist', function (): void {
    $orchestrator = new Orchestrator(
        Mockery::mock(DriverFactory::class),
        new OrchestratorConfig(logger: new NullLogger()),
    );

    $result = $orchestrator->safeExecute(
        new SpySafeExecuteTool(),
        [],
        agentId: 999_999,
        taskId: 1,
    );

    expect($result->success)->toBeTrue();

    // A missing agent resolves to the non-resolvable sentinel, so the owner
    // is null and a tool reading it fails closed rather than guessing a user.
    expect(SpySafeExecuteTool::$lastContext)->not->toBeNull()
        ->and(SpySafeExecuteTool::$lastContext->isResolvable())->toBeFalse()
        ->and(SpySafeExecuteTool::$lastUserId)->toBeNull()
        ->and(SpySafeExecuteTool::$lastTaskId)->toBe(1);
});
