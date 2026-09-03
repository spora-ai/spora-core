<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

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
