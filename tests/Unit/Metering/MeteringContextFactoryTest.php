<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Spora\Metering\MeteringContextFactory;
use Spora\Models\Task;

/**
 * The context is built from the task row alone: who owns the work, who
 * triggered it, and whether the platform started it.
 */
it('carries the owner, the trigger and the run kind off the task', function (): void {
    $userId = bootAuthLayer()->register('metering-context@example.com', 'Password1!', 'Metering');
    $principalId = createUserPrincipalPublic($userId);

    $agentId = $this->makeAgentWithPrincipal([], $userId);

    $task = Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => $principalId,
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'meter me',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    $context = (new MeteringContextFactory())->forTask($task);

    expect($context->taskId)->toBe((int) $task->id)
        ->and($context->agentId)->toBe($agentId)
        ->and($context->principalId)->toBe($principalId)
        ->and($context->triggerUserId)->toBe($userId)
        ->and($context->isSystemRun)->toBeFalse();
});

it('marks a task the platform started as a system run', function (): void {
    $userId = bootAuthLayer()->register('metering-system@example.com', 'Password1!', 'Metering');
    $principalId = createUserPrincipalPublic($userId);

    $agentId = $this->makeAgentWithPrincipal([], $userId);

    $task = Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => $principalId,
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'scheduled run',
        'step_count'      => 0,
        'max_steps'       => 10,
        'data'            => ['run_id' => 4242],
    ]);

    $context = (new MeteringContextFactory())->forTask($task);

    expect($context->isSystemRun)->toBeTrue();
});

it('has no context for a task that does not exist', function (): void {
    expect((new MeteringContextFactory())->forTaskId(999_999_999))->toBeNull();
});

it('reads the same values off a task it loads by id', function (): void {
    $userId = bootAuthLayer()->register('metering-by-id@example.com', 'Password1!', 'Metering');
    $principalId = createUserPrincipalPublic($userId);

    $agentId = $this->makeAgentWithPrincipal([], $userId);

    $task = Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => $principalId,
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => 'meter me',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    $context = (new MeteringContextFactory())->forTaskId((int) $task->id);

    expect($context)->not->toBeNull()
        ->and($context->taskId)->toBe((int) $task->id)
        ->and($context->principalId)->toBe($principalId);
});
