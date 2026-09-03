<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Console\Worker\WorkerReaper;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\BufferedOutput;

defined('REAPER_GROUP_PASSWORD') || define('REAPER_GROUP_PASSWORD', 'Password1!');
const REAPER_GROUP_DT = 'Y-m-d H:i:s';

function makeGroupReaper(): WorkerReaper
{
    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldIgnoreMissing();

    return new WorkerReaper(new NullLogger(), $notification, null);
}

function seedGroupAgent(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('reaper-group@example.com', REAPER_GROUP_PASSWORD, 'Reaper Group');
    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'      => 'ReaperGroupAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent->id];
}

describe('WorkerReaper — server:housekeeping lease prefix', function (): void {
    it('does NOT reap a server-driven tick under an active server:housekeeping lease', function (): void {
        // ScheduledRunProcessor::processSynchronously claims QUEUED rows
        // with lease_owner='server:housekeeping' before ticking. The reaper
        // must respect that — flipping it would erase the work-in-progress
        // context for a scheduled run that's still mid-tick.
        [$userId, $agentId] = seedGroupAgent();
        $task = Task::create([
            'agent_id'    => $agentId,
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'status'      => 'RUNNING',
            'user_prompt' => 'server housekeeping target',
            'max_steps'   => 10,
            'step_count'  => 0,
            'lease_owner' => 'server:housekeeping',
        ]);
        Capsule::table('tasks')->where('id', $task->id)->update([
            'lease_expires_at' => date(REAPER_GROUP_DT, time() + 5 * 60),
            'updated_at'       => date(REAPER_GROUP_DT, time() - 61 * 60),
        ]);

        $output = new BufferedOutput();
        makeGroupReaper()->reapStaleTasks($output, 60);

        $task->refresh();
        expect($task->status)->toBe('RUNNING')
            ->and($task->lease_owner)->toBe('server:housekeeping');
        expect($output->fetch())->not->toContain('Reaped');
    });

    it('DOES reap a server-driven tick whose server:housekeeping lease expired', function (): void {
        // The lease is the heartbeat. Once it expires, the server-driven
        // tick has lost its heartbeat and must be reaped just like any
        // other orphan — the lease owner prefix only matters while the
        // lease is active.
        [$userId, $agentId] = seedGroupAgent();
        $task = Task::create([
            'agent_id'    => $agentId,
            'principal_id' => createUserPrincipalPublic($userId),
            'trigger_user_id' => $userId,
            'status'      => 'RUNNING',
            'user_prompt' => 'stale server housekeeping target',
            'max_steps'   => 10,
            'step_count'  => 0,
            'lease_owner' => 'server:housekeeping',
        ]);
        Capsule::table('tasks')->where('id', $task->id)->update([
            'lease_expires_at' => date(REAPER_GROUP_DT, time() - 60),
            'updated_at'       => date(REAPER_GROUP_DT, time() - 61 * 60),
        ]);

        $output = new BufferedOutput();
        makeGroupReaper()->reapStaleTasks($output, 60);

        $task->refresh();
        expect($task->status)->toBe('FAILED')
            ->and($task->error_code)->toBe('WORKER_DISCONNECTED')
            ->and($task->lease_owner)->toBe('server:housekeeping');
    });
});
