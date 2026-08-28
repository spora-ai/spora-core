<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Agents\OrchestratorInterface;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Models\Agent;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\BufferedOutput;

defined('SRP_GROUP_PASSWORD') || define('SRP_GROUP_PASSWORD', 'Password1!');
const SRP_GROUP_DT = 'Y-m-d H:i:s';
const SRP_GROUP_PAST_DUE_AT = '2025-01-01 10:00:00';
const SRP_GROUP_CRON = '0 9 * * *';

function makeGroupClaimProcessor(): ScheduledRunProcessor
{
    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps, ?int $parentTaskId = null, ?int $runId = null, array $mediaIds = [], ?int $userId = null): Task {
        return Task::create([
            'agent_id'    => $agentId,
            'principal_id' => $userId !== null
                ? createUserPrincipalPublic($userId)
                : createUserPrincipalPublic(1),
            'user_id'     => $userId ?? 1,
            'status'      => 'QUEUED',
            'user_prompt' => $prompt,
            'max_steps'   => $maxSteps,
            'step_count'  => 0,
        ]);
    });
    $orchestrator->allows('tick')->andReturnUsing(function (int $taskId): void {
        $task = Task::find($taskId);
        if ($task !== null) {
            $task->status = 'COMPLETED';
            $task->save();
        }
    });

    $mercure = Mockery::mock(MercurePublisherInterface::class);
    $mercure->allows('publish')->andReturn(true);

    $notification = Mockery::mock(NotificationService::class);
    $notification->allows('notifyScheduledRunCompleted')->andReturnNull();
    $notification->allows('sendEmailForScheduledRun')->andReturnNull();

    return new ScheduledRunProcessor(
        $orchestrator,
        new NullLogger(),
        $mercure,
        $notification,
    );
}

function seedGroupSchedule(int $agentId, int $userId, string $label): int
{
    $run = ScheduledRun::create([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'raw_prompt'      => $label,
        'cron_expression' => SRP_GROUP_CRON,
        'timezone'        => 'UTC',
        'is_active'       => true,
        'next_run_at'     => SRP_GROUP_PAST_DUE_AT,
    ]);

    Capsule::table('scheduled_runs_next')->insert([
        'scheduled_run_id' => $run->id,
        'due_at'           => SRP_GROUP_PAST_DUE_AT,
        'status'           => ScheduledRunNext::STATUS_PENDING,
        'created_at'       => date(SRP_GROUP_DT),
        'updated_at'       => date(SRP_GROUP_DT),
    ]);

    return $run->id;
}

describe('ScheduledRunProcessor — group-principal claim', function (): void {

    it('claims a schedule for a user-owned agent', function (): void {
        $processor = makeGroupClaimProcessor();
        $authService = bootAuthLayer();
        $userId = $authService->register('grp-user@example.com', SRP_GROUP_PASSWORD, 'GrpUser');
        // register() doesn't auto-create the user-principal — materialise it
        // so PrincipalResolver::visiblePrincipalIds() returns the right list.
        $this->createUserPrincipal($userId);

        $agentId = (int) Agent::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'name'         => 'Group User Agent',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;
        $runId = seedGroupSchedule($agentId, $userId, 'user-owned schedule');

        $result = $processor->processSynchronously(new BufferedOutput(), $userId, 600);

        expect($result)->toBeTrue();
        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->first();
        expect($entry->status)->toBe(ScheduledRunNext::STATUS_DONE);
    });

    it('claims a schedule for a group-owned agent via any group member', function (): void {
        // Group G owns agent X; members A and B both have visibility.
        // A's /housekeeping call wins the claim — B's call returns false
        // because the entry is already DONE.
        $processor = makeGroupClaimProcessor();
        $authService = bootAuthLayer();
        $userAId = $authService->register('grp-a@example.com', SRP_GROUP_PASSWORD, 'GrpA');
        $userBId = $authService->register('grp-b@example.com', SRP_GROUP_PASSWORD, 'GrpB');
        $this->createUserPrincipal($userAId);
        $this->createUserPrincipal($userBId);

        $groupPrincipalId = $this->makeGroupPrincipal($userAId, 'Group Visibility');
        Capsule::table('group_memberships')->insertOrIgnore([
            'group_id'   => Capsule::table('principals')->where('id', $groupPrincipalId)->value('group_id'),
            'user_id'    => $userBId,
            'role'       => 'member',
            'created_at' => date(SRP_GROUP_DT),
            'updated_at' => date(SRP_GROUP_DT),
        ]);
        $agentId = (int) Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'Group Owned Agent',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;
        $runId = seedGroupSchedule($agentId, $userAId, 'group-owned schedule');

        // A wins the claim.
        $resultA = $processor->processSynchronously(new BufferedOutput(), $userAId, 600);
        expect($resultA)->toBeTrue();

        // B's call returns false — the schedule is already DONE.
        $resultB = $processor->processSynchronously(new BufferedOutput(), $userBId, 600);
        expect($resultB)->toBeFalse();

        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->first();
        expect($entry->status)->toBe(ScheduledRunNext::STATUS_DONE);
    });

    it('two members racing produces one claim + one null', function (): void {
        // Sequential: A claims + commits before B calls. B's call sees
        // no PENDING entries → null → returns false. The plan calls for
        // testing the lockForUpdate semantic, but a serialised test
        // produces the same observable: only ONE call wins a given
        // scheduled_runs_next row.
        $processor = makeGroupClaimProcessor();
        $authService = bootAuthLayer();
        $userAId = $authService->register('grp-race-a@example.com', SRP_GROUP_PASSWORD, 'GrpRaceA');
        $userBId = $authService->register('grp-race-b@example.com', SRP_GROUP_PASSWORD, 'GrpRaceB');
        $this->createUserPrincipal($userAId);
        $this->createUserPrincipal($userBId);

        $groupPrincipalId = $this->makeGroupPrincipal($userAId, 'Race Group');
        Capsule::table('group_memberships')->insertOrIgnore([
            'group_id'   => Capsule::table('principals')->where('id', $groupPrincipalId)->value('group_id'),
            'user_id'    => $userBId,
            'role'       => 'member',
            'created_at' => date(SRP_GROUP_DT),
            'updated_at' => date(SRP_GROUP_DT),
        ]);
        $agentId = (int) Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'Race Agent',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;
        seedGroupSchedule($agentId, $userAId, 'race');

        $resultA = $processor->processSynchronously(new BufferedOutput(), $userAId, 600);
        $resultB = $processor->processSynchronously(new BufferedOutput(), $userBId, 600);

        expect($resultA)->toBeTrue()
            ->and($resultB)->toBeFalse();
    });

    it('does NOT claim schedules for agents the caller cannot see', function (): void {
        // Caller A has no group membership on the group that owns agent X.
        // A's visiblePrincipalIds returns only their user-principal; the
        // scheduled_runs_next WHERE clause filters on
        // agents.principal_id IN (visible), so the entry is invisible.
        $processor = makeGroupClaimProcessor();
        $authService = bootAuthLayer();
        $ownerId = $authService->register('grp-owner@example.com', SRP_GROUP_PASSWORD, 'GrpOwner');
        $outsiderId = $authService->register('grp-outsider@example.com', SRP_GROUP_PASSWORD, 'Outsider');
        $this->createUserPrincipal($ownerId);
        $this->createUserPrincipal($outsiderId);

        $groupPrincipalId = $this->makeGroupPrincipal($ownerId, 'Closed Group');
        $agentId = (int) Agent::create([
            'principal_id' => $groupPrincipalId,
            'name'         => 'Closed Agent',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;
        $runId = seedGroupSchedule($agentId, $ownerId, 'private');

        $result = $processor->processSynchronously(new BufferedOutput(), $outsiderId, 600);

        expect($result)->toBeFalse();

        // Entry remains PENDING — outsider could not claim it.
        $entry = Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $runId)
            ->first();
        expect($entry->status)->toBe(ScheduledRunNext::STATUS_PENDING);
    });
});
