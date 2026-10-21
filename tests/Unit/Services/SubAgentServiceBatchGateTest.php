<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use InvalidArgumentException;
use Mockery;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\SubAgentService;

/**
 * Plan D invariants for the multi-child sub-agent stall fix.
 *
 * The race the production code guards against: a child worker completes
 * its tick concurrently with the parent's spawn sequence, fires the
 * per-child resume hook, and clears the parent's data. The parent's
 * subsequent `recordSpawnedChild` + `incrementExpectedCount` then race
 * against the cleared base and the resume gate at `count(spawned) !==
 * expected` permanently fails. Plan D's fix is the `sub_agent_batch_open`
 * flag in `tasks.data` — set at first spawn, cleared at the batch-boundary
 * hook.
 */
function planDSeedAgents(): array
{
    $ownerId = bootAuthLayer()->register('plan-d-owner@example.test', 'Password1!', 'Owner');
    $ownerPrincipalId = createUserPrincipalPublic($ownerId);
    $now = date('Y-m-d H:i:s');

    $parentAgentId = (int) Agent::create([
        'name'        => 'PlanD Parent',
        'user_id'     => $ownerId,
        'principal_id' => $ownerPrincipalId,
        'max_steps'   => 5,
        'is_active'   => true,
        'created_at'  => $now,
        'updated_at'  => $now,
    ])->id;

    $childAgentId = (int) Agent::create([
        'name'        => 'PlanD Child',
        'user_id'     => $ownerId,
        'principal_id' => $ownerPrincipalId,
        'max_steps'   => 5,
        'is_active'   => true,
        'created_at'  => $now,
        'updated_at'  => $now,
    ])->id;

    return ['ownerId' => $ownerId, 'parentAgentId' => $parentAgentId, 'childAgentId' => $childAgentId];
}

/**
 * Seed a parent in AWAITING_SUB_AGENTS with seeded child rows.
 *
 * Returns both the parent Task and the actual SQLite-assigned child ids.
 * The real ids are required because resumeParent iterates them; logical
 * ids from the caller are not used.
 *
 * @return array{parent: Task, childIds: list<int>}
 */
function planDSeedParentInAwaiting(int $expectedCount, int $spawnedCount, bool $batchOpen = true): array
{
    $seed = planDSeedAgents();
    $ownerPrincipalId = createUserPrincipalPublic($seed['ownerId']);
    $parent = Task::create([
        'agent_id'        => $seed['parentAgentId'],
        'principal_id'    => $ownerPrincipalId,
        'trigger_user_id' => $seed['ownerId'],
        'status'          => 'AWAITING_SUB_AGENTS',
        'user_prompt'     => 'plan d test',
        'max_steps'       => 5,
        'step_count'      => 0,
        'data'            => [
            'sub_agent_expected_count' => $expectedCount,
            'spawned_sub_task_ids'     => [],
            'sub_agent_batch_open'     => $batchOpen,
        ],
        'created_at'      => date('Y-m-d H:i:s'),
        'updated_at'      => date('Y-m-d H:i:s'),
    ]);

    $realIds = [];
    for ($i = 0; $i < $spawnedCount; $i++) {
        $child = Task::create([
            'agent_id'   => $seed['childAgentId'],
            'principal_id' => $ownerPrincipalId,
            'trigger_user_id' => $seed['ownerId'],
            'status'     => 'COMPLETED',
            'user_prompt' => 'child ' . $i,
            'max_steps'  => 5,
            'step_count' => 0,
            'parent_task_id' => $parent->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $realIds[] = (int) $child->id;
    }

    $data = $parent->data ?? [];
    $data['spawned_sub_task_ids'] = $realIds;
    $parent->data = $data;
    $parent->save();

    return ['parent' => $parent, 'childIds' => $realIds];
}

describe('SubAgentService::maybeResumeParent batch-open gate', function (): void {
    afterEach(function (): void {
        Mockery::close();
        \Spora\Core\Database::resetBootState();
    });

    it('refuses to resume a parent whose batch is still open', function (): void {
        // Simulate the race: a child worker completes mid-batch while the
        // parent is still spawning. The per-child hook must short-circuit
        // — only the batch-boundary hook clears the flag and resumes.
        $seed = planDSeedParentInAwaiting(expectedCount: 2, spawnedCount: 1, batchOpen: true);
        $parent = $seed['parent'];
        $childIds = $seed['childIds'];

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        // appendHistory MUST NOT be called — the gate refuses the resume.
        $orchestrator->shouldNotReceive('appendHistory');

        $service = new SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
        );

        $service->maybeResumeParent($childIds[0]);

        // Parent must still be AWAITING_SUB_AGENTS with the batch flag
        // still set.
        $parent->refresh();
        expect($parent->status)->toBe('AWAITING_SUB_AGENTS');
        $data = (array) $parent->data;
        expect($data['sub_agent_batch_open'])->toBeTrue();
    });

    it('falls through when the batch flag is clear and the gate passes', function (): void {
        // Baseline: gate flag cleared, but spawned != expected → mid-batch
        // check still refuses to resume.
        $seed = planDSeedParentInAwaiting(expectedCount: 2, spawnedCount: 1, batchOpen: false);
        $parent = $seed['parent'];
        $childIds = $seed['childIds'];

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('appendHistory');

        $service = new SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
        );

        $service->maybeResumeParent($childIds[0]);

        $parent->refresh();
        expect($parent->status)->toBe('AWAITING_SUB_AGENTS');
    });
});

describe('SubAgentService::resumeParent idempotency', function (): void {
    afterEach(function (): void {
        Mockery::close();
        \Spora\Core\Database::resetBootState();
    });

    it('flips the parent to QUEUED exactly once under double invocation', function (): void {
        // Worker mode can race: the batch-boundary hook flips QUEUED and
        // a slow sibling's per-child hook tries to resume the same parent
        // a moment later. resumeParent must be idempotent.
        $seed = planDSeedParentInAwaiting(expectedCount: 1, spawnedCount: 1, batchOpen: false);
        $parent = $seed['parent'];
        $childIds = $seed['childIds'];

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        // appendHistory called exactly once — the second invocation sees
        // status=QUEUED under lockForUpdate and short-circuits.
        $orchestrator->shouldReceive('appendHistory')->once();

        $service = new SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
        );

        $first = $service->resumeParent($parent, $childIds);
        $second = $service->resumeParent($parent, $childIds);

        expect($first)->toBeTrue();
        expect($second)->toBeFalse();

        $parent->refresh();
        expect($parent->status)->toBe('QUEUED');
    });

    it('returns false when the parent is no longer AWAITING_SUB_AGENTS', function (): void {
        $seed = planDSeedParentInAwaiting(expectedCount: 1, spawnedCount: 1, batchOpen: false);
        $parent = $seed['parent'];
        $childIds = $seed['childIds'];
        // Simulate a concurrent resume by flipping the status out from
        // under the caller.
        Task::where('id', $parent->id)->update(['status' => 'QUEUED']);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('appendHistory');

        $service = new SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
        );

        $result = $service->resumeParent($parent, $childIds);
        expect($result)->toBeFalse();
    });
});

describe('SubAgentService::maybeResumeParentForParent batch-boundary hook', function (): void {
    afterEach(function (): void {
        Mockery::close();
        \Spora\Core\Database::resetBootState();
    });

    it('clears the batch flag before evaluating the resume gate', function (): void {
        // The boundary hook MUST close the batch so a subsequent slow
        // sibling's per-child hook can flow through.
        $seed = planDSeedParentInAwaiting(expectedCount: 1, spawnedCount: 1, batchOpen: true);
        $parent = $seed['parent'];

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldReceive('appendHistory')->zeroOrMoreTimes();

        $service = new SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
        );

        $service->maybeResumeParentForParent($parent->id);

        $parent->refresh();
        $data = (array) $parent->data;
        expect($data['sub_agent_batch_open'] ?? false)->toBeFalse();
    });
});

/**
 * A parent task plus one settled child it started, for the
 * `continueChild` scenarios.
 *
 * @return array{parent: Task, child: Task, ownerId: int}
 */
function continueChildFixture(string $childStatus = 'COMPLETED', ?int $childParentId = null): array
{
    $seed = planDSeedAgents();
    $ownerPrincipalId = createUserPrincipalPublic($seed['ownerId']);

    $parent = Task::create([
        'agent_id'        => $seed['parentAgentId'],
        'principal_id'    => $ownerPrincipalId,
        'trigger_user_id' => $seed['ownerId'],
        'status'          => 'RUNNING',
        'user_prompt'     => 'parent prompt',
        'max_steps'       => 5,
        'step_count'      => 1,
    ]);

    $child = Task::create([
        'agent_id'        => $seed['childAgentId'],
        'principal_id'    => $ownerPrincipalId,
        'trigger_user_id' => $seed['ownerId'],
        'status'          => $childStatus,
        'user_prompt'     => 'child prompt',
        'max_steps'       => 5,
        'step_count'      => 1,
        'parent_task_id'  => $childParentId ?? $parent->id,
    ]);

    return ['parent' => $parent, 'child' => $child, 'ownerId' => $seed['ownerId']];
}

describe('SubAgentService::continueChild', function (): void {
    afterEach(function (): void {
        Mockery::close();
        \Spora\Core\Database::resetBootState();
    });

    it('refuses a task this conversation did not start', function (): void {
        $seed = planDSeedAgents();
        $ownerPrincipalId = createUserPrincipalPublic($seed['ownerId']);

        $parent = Task::create([
            'agent_id'        => $seed['parentAgentId'],
            'principal_id'    => $ownerPrincipalId,
            'trigger_user_id' => $seed['ownerId'],
            'status'          => 'RUNNING',
            'user_prompt'     => 'parent prompt',
            'max_steps'       => 5,
        ]);

        $foreignChild = Task::create([
            'agent_id'        => $seed['childAgentId'],
            'principal_id'    => $ownerPrincipalId,
            'trigger_user_id' => $seed['ownerId'],
            'status'          => 'COMPLETED',
            'user_prompt'     => 'child prompt',
            'max_steps'       => 5,
        ]);

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('continue');

        $service = new SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
        );

        expect(fn() => $service->continueChild(
            parentTaskId: $parent->id,
            childTaskId: $foreignChild->id,
            prompt: 'more',
            userId: $seed['ownerId'],
        ))->toThrow(
            InvalidArgumentException::class,
            "Task #{$foreignChild->id} was not started by this conversation.",
        );
    });

    it('refuses a task that is still running', function (): void {
        $fixture = continueChildFixture(childStatus: 'RUNNING');

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->shouldNotReceive('continue');

        $service = new SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
        );

        expect(fn() => $service->continueChild(
            parentTaskId: $fixture['parent']->id,
            childTaskId: $fixture['child']->id,
            prompt: 'more',
            userId: $fixture['ownerId'],
        ))->toThrow(
            InvalidArgumentException::class,
            "Task #{$fixture['child']->id} is still running (status RUNNING).",
        );
    });

    it('records one expected child and one spawned id for the continue round', function (): void {
        // The continue round reuses the spawn bookkeeping: the parent
        // suspends again and the resume gate compares the spawned ids
        // against the expected count.
        $fixture = continueChildFixture();

        $orchestrator = Mockery::mock(OrchestratorInterface::class);
        $orchestrator->allows('continue')
            ->with($fixture['child']->id, 'more')
            ->andReturn($fixture['child']);

        $service = new SubAgentService(
            static fn(): OrchestratorInterface => $orchestrator,
        );

        $service->continueChild(
            parentTaskId: $fixture['parent']->id,
            childTaskId: $fixture['child']->id,
            prompt: 'more',
            userId: $fixture['ownerId'],
        );

        $fixture['parent']->refresh();
        $data = (array) $fixture['parent']->data;

        expect($fixture['parent']->status)->toBe('AWAITING_SUB_AGENTS')
            ->and($data['spawned_sub_task_ids'])->toBe([$fixture['child']->id])
            ->and($data['sub_agent_expected_count'])->toBe(1);
    });
});
