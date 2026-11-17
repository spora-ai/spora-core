<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\SubAgentService;

/**
 * A parent must not settle on a child that is about to be retried.
 *
 * The retry scheduler arms the retry on the failed child itself and
 * leaves the row FAILED, so the child looks terminal to every gate that
 * asks "has this child finished?". It has not: the worker daemon will
 * claim the row once `retry_after` elapses and run the child again.
 *
 * A parent that settles on that failure imports the failure into its own
 * history, drops its child bookkeeping and goes back to the queue. The
 * retry then lands on a parent that is no longer waiting — and the
 * retry's result is thrown away.
 */

/**
 * A parent parked on one child, and that child in the given state.
 *
 * The child carries `retry_after` when a retry is pending — the marker the
 * scheduler writes — and `retry_count` once it has been retried.
 *
 * @return array{parent: Task, child: Task}
 */
function pendingRetrySeedParentAndChild(
    string $childStatus,
    ?string $retryAfter = null,
    int $retryCount = 0,
): array {
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = $authService->register(
        sprintf('pending-retry-%d@example.com', $seq),
        'Password1!',
        'Pending Retry',
    );
    $principalId = createUserPrincipalPublic($userId);

    $agent = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Pending Retry Agent',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $parent = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => $principalId,
        'trigger_user_id' => $userId,
        'status'          => 'AWAITING_SUB_AGENTS',
        'user_prompt'     => 'delegate this',
        'max_steps'       => 5,
        'step_count'      => 0,
    ]);

    $child = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => $principalId,
        'trigger_user_id' => $userId,
        'status'          => $childStatus,
        'user_prompt'     => 'do that',
        'max_steps'       => 5,
        'step_count'      => 0,
        'parent_task_id'  => $parent->id,
        'retry_count'     => $retryCount,
    ]);

    if ($retryAfter !== null) {
        Capsule::table('tasks')->where('id', $child->id)->update(['retry_after' => $retryAfter]);
    }

    $parent->update([
        'data' => [
            'spawned_sub_task_ids'     => [$child->id],
            'sub_agent_expected_count' => 1,
        ],
    ]);

    return ['parent' => $parent->refresh(), 'child' => $child->refresh()];
}

describe('SubAgentService and a child whose retry is pending', function (): void {
    afterEach(function (): void {
        Mockery::close();
        Spora\Core\Database::resetBootState();
    });

    it('does not settle the parent on a child that is about to be retried', function (): void {
        $fixture = pendingRetrySeedParentAndChild('FAILED', '2026-11-16 21:02:15');

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        // The child's answer must not be imported: the child is going to
        // run again.
        $orchestrator->shouldNotReceive('appendHistory');

        $service = new SubAgentService(static fn(): OrchestratorInterface => $orchestrator);
        $service->maybeResumeParent($fixture['child']->id);

        $parent = $fixture['parent']->refresh();
        $data = (array) $parent->data;

        expect($parent->status)->toBe('AWAITING_SUB_AGENTS')
            ->and($data['spawned_sub_task_ids'])->toBe([$fixture['child']->id])
            ->and($data['sub_agent_expected_count'])->toBe(1);
    });

    it('does not settle the parent at the batch boundary either', function (): void {
        // The other gate: the batch-boundary hook runs after every
        // approved tool in a batch and resumes the parent on its own
        // terms. It has to refuse a child that is waiting on a retry too.
        $fixture = pendingRetrySeedParentAndChild('FAILED', '2026-11-16 21:02:15');

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('appendHistory');

        $service = new SubAgentService(static fn(): OrchestratorInterface => $orchestrator);
        $service->maybeResumeParentForParent($fixture['parent']->id);

        expect($fixture['parent']->refresh()->status)->toBe('AWAITING_SUB_AGENTS');
    });

    it('settles the parent once the retry has completed the child', function (): void {
        // The other half of the chain: the worker claimed the retry,
        // cleared `retry_after` and the child completed. The parent is
        // owed the child's answer now.
        $fixture = pendingRetrySeedParentAndChild('COMPLETED', null, retryCount: 1);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldReceive('appendHistory')->once();

        $service = new SubAgentService(static fn(): OrchestratorInterface => $orchestrator);
        $service->maybeResumeParent($fixture['child']->id);

        $parent = $fixture['parent']->refresh();

        expect($parent->status)->toBe('QUEUED')
            ->and((array) $parent->data)->not->toHaveKey('spawned_sub_task_ids');
    });

    it('settles the parent on a child that is dead for good', function (): void {
        // The counterpart: a child that failed and will not run again
        // settles the parent, exactly as before.
        $fixture = pendingRetrySeedParentAndChild('FAILED');

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldReceive('appendHistory')->once();

        $service = new SubAgentService(static fn(): OrchestratorInterface => $orchestrator);
        $service->maybeResumeParent($fixture['child']->id);

        expect($fixture['parent']->refresh()->status)->toBe('QUEUED');
    });
});
