<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Console\Worker\WorkerReaper;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\BufferedOutput;

defined('REAPER_TEST_PASSWORD') || define('REAPER_TEST_PASSWORD', 'Password1!');
const REAPER_DT = 'Y-m-d H:i:s';

function makeReaper(): WorkerReaper
{
    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldIgnoreMissing();

    return new WorkerReaper(new NullLogger(), $notification);
}

function seedReaperAgent(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('reaper@example.com', REAPER_TEST_PASSWORD, 'Reaper');
    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'      => 'ReaperAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent->id];
}

function createReaperTask(int $agentId, int $userId, array $overrides): Task
{
    $defaults = [
        'agent_id'    => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'reaper target',
        'max_steps'   => 10,
        'step_count'  => 0,
    ];

    $task = Task::create(array_merge($defaults, $overrides));

    // Overrides that touch updated_at / lease_expires_at must write directly
    // because Eloquent auto-refreshes those on save().
    $directUpdates = [];
    if (array_key_exists('updated_at', $overrides)) {
        $directUpdates['updated_at'] = $overrides['updated_at'];
    }
    if (array_key_exists('lease_expires_at', $overrides)) {
        $directUpdates['lease_expires_at'] = $overrides['lease_expires_at'];
    }
    if (array_key_exists('lease_owner', $overrides)) {
        $directUpdates['lease_owner'] = $overrides['lease_owner'];
    }
    if (array_key_exists('retry_of_task_id', $overrides)) {
        $directUpdates['retry_of_task_id'] = $overrides['retry_of_task_id'];
    }
    if ($directUpdates !== []) {
        Capsule::table('tasks')->where('id', $task->id)->update($directUpdates);
    }

    return $task;
}

describe('WorkerReaper::reapStaleTasks', function (): void {
    it('reaps a RUNNING task whose lease expired AND updated_at is past the stale threshold', function (): void {
        [$userId, $agentId] = seedReaperAgent();
        $task = createReaperTask($agentId, $userId, [
            'lease_expires_at' => date(REAPER_DT, time() - 60),
            'updated_at'       => date(REAPER_DT, time() - 61 * 60),
        ]);

        $output = new BufferedOutput();
        makeReaper()->reapStaleTasks($output, 60);

        $task->refresh();
        expect($task->status)->toBe('FAILED')
            ->and($task->error_code)->toBe('WORKER_DISCONNECTED')
            ->and($task->failure_reason)->toContain('lease expired');
        expect($output->fetch())->toContain('Reaped 1');
    });

    it('does NOT reap a RUNNING task with an active lease', function (): void {
        // Active lease → reaper must leave the row alone even if the
        // task has been quiet past the stale threshold (the lease is the
        // heartbeat the lease-aware reaper honours).
        [$userId, $agentId] = seedReaperAgent();
        $task = createReaperTask($agentId, $userId, [
            'lease_expires_at' => date(REAPER_DT, time() + 5 * 60),
            'updated_at'       => date(REAPER_DT, time() - 61 * 60),
        ]);

        $output = new BufferedOutput();
        makeReaper()->reapStaleTasks($output, 60);

        $task->refresh();
        expect($task->status)->toBe('RUNNING');
        expect($output->fetch())->not->toContain('Reaped');
    });

    it('does NOT reap a retry-chain task (retry_of_task_id IS NOT NULL)', function (): void {
        // Retry-chain tasks belong to the root's failure context — the
        // reaper must NOT flip them, otherwise a reaper pass would race a
        // pending retry and erase the root's state.
        [$userId, $agentId] = seedReaperAgent();
        $root = createReaperTask($agentId, $userId, [
            'lease_expires_at' => null,
            'updated_at'       => date(REAPER_DT, time() - 5 * 60),
        ]);
        $retry = createReaperTask($agentId, $userId, [
            'retry_of_task_id' => $root->id,
            'lease_expires_at' => date(REAPER_DT, time() - 60),
            'updated_at'       => date(REAPER_DT, time() - 61 * 60),
        ]);

        $output = new BufferedOutput();
        makeReaper()->reapStaleTasks($output, 60);

        $retry->refresh();
        expect($retry->status)->toBe('RUNNING');
        expect($output->fetch())->not->toContain('Reaped');
    });

    it('reaps RUNNING tasks with NULL lease_expires_at (legacy orphaned, post-Phase 1 row)', function (): void {
        // Pre-Phase-1 installs leave legacy rows without lease columns populated.
        // The reaper must still flip them — the SQL accepts NULL lease_expires_at
        // as "no live lease held", same as an expired lease.
        [$userId, $agentId] = seedReaperAgent();
        $task = createReaperTask($agentId, $userId, [
            'lease_expires_at' => null,
            'updated_at'       => date(REAPER_DT, time() - 2 * 60 * 60),
        ]);

        $output = new BufferedOutput();
        makeReaper()->reapStaleTasks($output, 60);

        $task->refresh();
        expect($task->status)->toBe('FAILED')
            ->and($task->error_code)->toBe('WORKER_DISCONNECTED');
    });

    it('returns early without update if staleMinutes <= 0', function (): void {
        // Defensive: operator can pass 0 to disable reaping entirely
        // (WorkerRunCommand --stale-minutes=0). The reaper must not flip
        // anything even with ancient rows present.
        [$userId, $agentId] = seedReaperAgent();
        $task = createReaperTask($agentId, $userId, [
            'lease_expires_at' => null,
            'updated_at'       => date(REAPER_DT, time() - 24 * 60 * 60),
        ]);

        $output = new BufferedOutput();
        makeReaper()->reapStaleTasks($output, 0);

        $task->refresh();
        expect($task->status)->toBe('RUNNING');
        expect($output->fetch())->toBe('');
    });
});
