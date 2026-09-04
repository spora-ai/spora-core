<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use Spora\Console\Worker\WorkerReaper;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Spora\Services\SubAgentServiceInterface;
use Symfony\Component\Console\Output\BufferedOutput;

defined('RESUME_PASSWORD') || define('RESUME_PASSWORD', 'Password1!');
const RESUME_DT = 'Y-m-d H:i:s';

/**
 * Build a reaper that receives an injected sub-agent service. Direct
 * constructor wiring — no container — so tests can hand a fresh Mockery
 * mock to each test and inspect exactly which calls fire.
 *
 * The notification mock is silenced via {@code shouldIgnoreMissing()} so the
 * reaper's notify-task-orphaned leg doesn't blow up; this file is testing
 * the resume hook, not the notification side-effect.
 */
function makeReaperWithSubAgent(SubAgentServiceInterface $subAgent, LoggerInterface $logger): WorkerReaper
{
    $notification = Mockery::mock(NotificationService::class);
    $notification->shouldIgnoreMissing();

    return new WorkerReaper($logger, $notification, $subAgent);
}

function seedResumeAgent(): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('reaper-resume@example.com', RESUME_PASSWORD, 'Reaper Resume');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'ReaperResumeAgent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    return [$userId, $agent->id];
}

function createResumeChild(int $agentId, int $userId, int $parentId): Task
{
    $defaults = [
        'agent_id'         => $agentId,
        'principal_id'     => createUserPrincipalPublic($userId),
        'trigger_user_id'  => $userId,
        'status'           => 'RUNNING',
        'user_prompt'      => 'orphan child',
        'max_steps'        => 10,
        'step_count'       => 0,
        'parent_task_id'   => $parentId,
    ];
    $task = Task::create($defaults);
    Capsule::table('tasks')->where('id', $task->id)->update([
        'lease_expires_at' => date(RESUME_DT, time() - 60),
        'updated_at'       => date(RESUME_DT, time() - 61 * 60),
    ]);
    return $task;
}

function createAwaitingParent(int $agentId, int $userId): Task
{
    // The parent stays parked in AWAITING_SUB_AGENTS with an *active* lease
    // — the residual class is "one child is ungracefully killed, the
    // siblings keep progress alive so the parent's lease stays fresh".
    // Reaping the parent itself is Plan D's Plan-D-stall path, separate
    // from this hook. Here we only expect the dead child to be swept.
    $defaults = [
        'agent_id'         => $agentId,
        'principal_id'     => createUserPrincipalPublic($userId),
        'trigger_user_id'  => $userId,
        'status'           => 'AWAITING_SUB_AGENTS',
        'user_prompt'      => 'awaiting parent',
        'max_steps'        => 10,
        'step_count'       => 0,
    ];
    $task = Task::create($defaults);
    Capsule::table('tasks')->where('id', $task->id)->update([
        'lease_expires_at' => date(RESUME_DT, time() + 5 * 60),
    ]);
    return $task;
}

/**
 * Build a Monolog-backed logger + TestHandler pair so tests can inspect
 * the structured records the reaper emits.
 *
 * @return array{0: LoggerInterface, 1: TestHandler}
 */
function makeResumeLogger(): array
{
    $handler = new TestHandler();
    $logger  = new MonologLogger('test', [$handler]);
    return [$logger, $handler];
}

describe('WorkerReaper — resume hook for orphaned sub-agent children', function (): void {
    it('calls resume on a reaped child whose parent is still AWAITING_SUB_AGENTS', function (): void {
        [$userId, $agentId] = seedResumeAgent();

        // Parent in the parent_task_id column is still AWAITING_SUB_AGENTS
        // when the reaper sweeps — that is the residual class from
        // plans/worker-hardening-and-stdout-routing.md (a child killed by a
        // worker crash leaves its parent parked). The hook must fire.
        $parent = createAwaitingParent($agentId, $userId);
        $child  = createResumeChild($agentId, $userId, $parent->id);

        /** @var Mockery\MockInterface&SubAgentServiceInterface $subAgent */
        $subAgent = Mockery::mock(SubAgentServiceInterface::class);
        // The per-child hook is what this test cares about; the batch hook
        // is also exercised for completeness.
        $subAgent->shouldReceive('maybeResumeParent')->once()->with($child->id);
        $subAgent->shouldReceive('maybeResumeParentForParent')->once()->with($parent->id);

        [$logger] = makeResumeLogger();
        $output = new BufferedOutput();
        makeReaperWithSubAgent($subAgent, $logger)->reapStaleTasks($output, 60);

        $child->refresh();
        // The reaper still flipped the row to FAILED before the hook fires;
        // the hook is a recovery attempt, not a substitute for the sweep.
        expect($child->status)->toBe('FAILED')
            ->and($child->error_code)->toBe('WORKER_DISCONNECTED');
    });

    it('swallows sub-agent service exceptions and completes the sweep', function (): void {
        [$userId, $agentId] = seedResumeAgent();

        $parent = createAwaitingParent($agentId, $userId);
        $child  = createResumeChild($agentId, $userId, $parent->id);

        [$logger, $handler] = makeResumeLogger();
        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldIgnoreMissing();

        /** @var Mockery\MockInterface&SubAgentServiceInterface $subAgent */
        $subAgent = Mockery::mock(SubAgentServiceInterface::class);
        $subAgent->shouldReceive('maybeResumeParent')
            ->once()
            ->andThrow(new RuntimeException('boom: sub-agent service is sick'));

        $reaper = new WorkerReaper($logger, $notification, $subAgent);

        // Must not throw — the reaper sweep is more important than the
        // resume hook; if the latter fails the former must still complete.
        $output = new BufferedOutput();
        expect(fn() => $reaper->reapStaleTasks($output, 60))
            ->not()->toThrow(Throwable::class);

        $child->refresh();
        expect($child->status)->toBe('FAILED')
            ->and($child->error_code)->toBe('WORKER_DISCONNECTED');

        // Failure must be logged loudly with the structured context so
        // operators can correlate with the reaper's overall count line.
        $failureRecords = array_values(array_filter(
            $handler->getRecords(),
            static fn(Monolog\LogRecord $r): bool => $r->message === 'reaper_resume_failed',
        ));
        expect($failureRecords)->not->toBeEmpty();

        $context = $failureRecords[0]->context;
        expect($context['task_id'])->toBe($child->id)
            ->and($context['parent_task_id'])->toBe($parent->id)
            ->and($context['exception'])->toBe('boom: sub-agent service is sick');
    });

    it('is a no-op for resume when no SubAgentService is injected', function (): void {
        [$userId, $agentId] = seedResumeAgent();
        $parent = createAwaitingParent($agentId, $userId);
        $child  = createResumeChild($agentId, $userId, $parent->id);

        [$logger, $handler] = makeResumeLogger();

        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldIgnoreMissing();

        // Third ctor arg left at its default (`null`) — the resume hook
        // is fully opt-in. The reaper must complete the sweep unchanged.
        $reaper = new WorkerReaper($logger, $notification);

        $output = new BufferedOutput();
        expect(fn() => $reaper->reapStaleTasks($output, 60))
            ->not()->toThrow(Throwable::class);

        $child->refresh();
        expect($child->status)->toBe('FAILED')
            ->and($child->error_code)->toBe('WORKER_DISCONNECTED');

        // No resume-related log lines ever fire (success or failure)
        // because the hook is wired off. The reaper's regular orphan
        // report must still appear so operators see the sweep happen.
        $resumeLogs = array_filter(
            $handler->getRecords(),
            static fn(Monolog\LogRecord $r): bool => $r->message === 'reaper_resume_attempt'
                || $r->message === 'reaper_resume_failed',
        );
        expect($resumeLogs)->toBe([]);
    });
});
