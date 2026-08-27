<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Spora\Services\AgentServiceInterface;

afterEach(function (): void {
    SpySafeExecuteTool::$lastTaskId = null;
    SpySafeExecuteTool::$lastUserId = null;
});

test('safeExecute resolves $userId from the calling Agent row, never from session', function (): void {
    // `userId` is no longer a parameter on `safeExecute()` — the
    // Orchestrator fills it from the calling Agent's row, so a tool
    // can never receive a session-derived value.
    /** @var AgentServiceInterface&MockInterface $agentService */
    $agentService = Mockery::mock(AgentServiceInterface::class);
    $caller = new Agent();
    $caller->id = 7;
    $caller->principal_id = 1;
    $agentService->allows('getAgentByAgentId')->andReturn($caller);

    $orchestrator = new Orchestrator(
        Mockery::mock(DriverFactory::class),
        new OrchestratorConfig(
            toolInstances: [new SpySafeExecuteTool()],
            logger: new NullLogger(),
            agentService: $agentService,
        ),
    );

    $result = $orchestrator->safeExecute(
        new SpySafeExecuteTool(),
        [],
        agentId: 7,
        taskId: 1234,
    );

    expect($result->success)->toBeTrue();
    expect(SpySafeExecuteTool::$lastTaskId)->toBe(1234);
    // The legacy `$userId` shim is null for an in-memory Agent stub
    // without a persisted principal row; the principal context (passed
    // separately) is the source of truth. The tool still receives the
    // legacy int for plugin back-compat.
    expect(SpySafeExecuteTool::$lastUserId)->toBeNull();
});

test('safeExecute passes null $userId when the agentService is not configured', function (): void {
    // Minimal boot (no AgentService) — the tool's own
    // getAgentByAgentId() fallback applies for ownership checks.
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
    );

    expect(SpySafeExecuteTool::$lastUserId)->toBeNull();
    expect(SpySafeExecuteTool::$lastTaskId)->toBe(1);
});
