<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\TaskService;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

function descendantGuardService(?OrchestratorInterface $orchestrator = null): TaskService
{
    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();

    return new TaskService(
        $orchestrator ?? Mockery::mock(OrchestratorInterface::class),
        $mercure,
        Mockery::mock(Spora\Services\TaskCompactionServiceInterface::class),
        null,
        new PrincipalResolver(),
    );
}

function descendantGuardTask(int $agentId, int $principalId, ?int $parentTaskId, string $status = 'COMPLETED'): Task
{
    return Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => $principalId,
        'trigger_user_id' => null,
        'status'          => $status,
        'user_prompt'     => 'a turn',
        'step_count'      => 0,
        'max_steps'       => 10,
        'parent_task_id'  => $parentTaskId,
    ]);
}

it('keeps a descendant that belongs to another principal when the parent is deleted', function (): void {
    $auth = bootAuthLayer();
    $owner = $auth->register('descendant-owner@example.com', TEST_PASSWORD, 'Owner');
    $other = $auth->register('descendant-other@example.com', TEST_PASSWORD, 'Other');

    $ownerPrincipalId = createUserPrincipalPublic($owner);
    $otherPrincipalId = createUserPrincipalPublic($other);

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $ownerPrincipalId,
        'name'         => 'Parent Agent',
        'max_steps'    => 10,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $parent = descendantGuardTask($agentId, $ownerPrincipalId, null);
    $child  = descendantGuardTask($agentId, $otherPrincipalId, $parent->id);

    $service = descendantGuardService();

    expect($service->deleteTask($parent->id, $owner))->toBeTrue();

    // The child belongs to another principal; the `parent_task_id` foreign
    // key cascades, so a plain delete would take it with the parent.
    $survivor = Task::find($child->id);
    expect($survivor)->not->toBeNull()
        ->and($survivor->parent_task_id)->toBeNull();
});

it('does not abort an ancestor that is outside the caller\'s principals', function (): void {
    $auth = bootAuthLayer();
    $owner = $auth->register('cascade-owner@example.com', TEST_PASSWORD, 'Owner');
    $other = $auth->register('cascade-other@example.com', TEST_PASSWORD, 'Other');

    $ownerPrincipalId = createUserPrincipalPublic($owner);
    $otherPrincipalId = createUserPrincipalPublic($other);

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $ownerPrincipalId,
        'name'         => 'Cascade Agent',
        'max_steps'    => 10,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    // The ancestor belongs to the owner's principal; the child to the other
    // user's — a link the other user can see but not the ancestor.
    $ancestor = descendantGuardTask($agentId, $ownerPrincipalId, null, 'AWAITING_SUB_AGENTS');
    $child    = descendantGuardTask($agentId, $otherPrincipalId, $ancestor->id, 'RUNNING');

    $aborted = [];
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->shouldReceive('abort')->andReturnUsing(static function (int $taskId) use (&$aborted): Task {
        $aborted[] = $taskId;
        $task = Task::find($taskId);
        $task->status = 'ABORTED';
        $task->save();
        return $task;
    });

    $service = descendantGuardService($orchestrator);

    $service->abortSubAgentAndCascade($child->id, $other);

    expect($aborted)->toBe([$child->id])
        ->and(Task::find($ancestor->id)->status)->toBe('AWAITING_SUB_AGENTS');
});
