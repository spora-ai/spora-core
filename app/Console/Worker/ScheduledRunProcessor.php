<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\PrincipalResolver;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Owns the scheduled-run lifecycle: claim next due entry, build the prompt,
 * start the orchestrator, mark the entry DONE, and queue the next occurrence.
 */
final class ScheduledRunProcessor
{
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** Tracks how many scheduled runs were processed in the last process() call (testing hook). */
    public int $lastProcessed = 0;

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherInterface $mercure,
        private readonly NotificationService $notificationService,
    ) {}

    public function process(OutputInterface $output): void
    {
        $context = $this->claimNextScheduledRun();
        if ($context === null) {
            return;
        }

        $entry = $context['entry'];
        $run = $context['run'];
        $agent = $context['agent'];

        $template = null;
        if ($run->template_id !== null) {
            $template = AgentPromptTemplate::find($run->template_id);
        }

        $prompt = $this->buildPrompt($run, $template, $agent);
        $maxSteps = $this->resolveMaxSteps($run, $template, $agent);

        $this->logger->info('Triggering scheduled run', [
            'run_id' => $run->id,
            'agent_id' => $run->agent_id,
        ]);
        $output->writeln(sprintf('<info>Triggering scheduled run %d for agent %d...</info>', $run->id, $run->agent_id));

        try {
            $task = $this->orchestrator->start((int) $run->agent_id, $prompt, (int) $maxSteps, null, $run->id);
        } catch (Throwable $e) {
            $this->logger->error('Scheduled run failed', [
                'run_id' => $run->id,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $output->writeln(sprintf('<error>Scheduled run %d failed: %s</error>', $run->id, $e->getMessage()));

            // Symmetric with finalizeScheduledRun(): mark SKIPPED and either queue the next
            // PENDING (recurring) or deactivate (one-shot) — all in one transaction so the
            // failure path cannot leave the schedule in a zombie state.
            $completedAt = gmdate(self::DB_DATETIME_FORMAT);
            $nextDueAt = $this->computeNextDueAt($run);
            Capsule::connection()->transaction(function () use ($entry, $completedAt, $nextDueAt, $run): void {
                Capsule::table('scheduled_runs_next')
                    ->where('id', $entry->id)
                    ->update([
                        'status'       => ScheduledRunNext::STATUS_SKIPPED,
                        'completed_at' => $completedAt,
                    ]);
                if ($nextDueAt !== null) {
                    $this->insertRecurringEntry($run, $nextDueAt, $completedAt);
                    $this->updateRecurringRun($run, $completedAt, $nextDueAt);
                } else {
                    $this->deactivateRun($run, $completedAt);
                }
            });
            return;
        }

        $this->finalizeScheduledRun($run, $entry, $task);
        $this->lastProcessed = 1;
    }

    /**
     * Client-mode counterpart to {@see process()}: claim a scheduled run the
     * caller is allowed to see, dispatch it, AND drive the orchestrator to
     * completion in this request — so the browser that hit /worker/housekeeping
     * never has to poll the result task separately.
     *
     * @return bool  true iff a scheduled run was claimed and dispatched
     */
    public function processSynchronously(
        OutputInterface $output,
        int $userId,
        int $tickLeaseSeconds,
    ): bool {
        $resolver = new PrincipalResolver();
        // Auth guarantees the user-principal row exists by the time we get here
        // (login/register flows call PrincipalService::ensureUserPrincipal($userId)
        // before issuing the session token). If for any reason it doesn't — e.g.
        // a mid-flight logout or a manual SQL poke — visiblePrincipalIds() returns
        // [] and we skip the call without erroring. The next /housekeeping call
        // (within 5 min) will retry once auth has settled.
        $visiblePrincipalIds = $resolver->visiblePrincipalIds($userId);
        if ($visiblePrincipalIds === []) {
            $this->logger->warning('Scheduled-run dispatch skipped: caller has no visible principals', [
                'caller_user_id' => $userId,
            ]);
            return false;
        }

        $context = $this->claimNextScheduledRunForPrincipals($visiblePrincipalIds);
        if ($context === null) {
            return false;
        }

        $entry = $context['entry'];
        $run = $context['run'];
        $agent = $context['agent'];

        $template = null;
        if ($run->template_id !== null) {
            $template = AgentPromptTemplate::find($run->template_id);
        }

        $prompt = $this->buildPrompt($run, $template, $agent);
        $maxSteps = $this->resolveMaxSteps($run, $template, $agent);

        $this->logger->info('Triggering scheduled run (client mode)', [
            'run_id'         => $run->id,
            'agent_id'       => $run->agent_id,
            'caller_user_id' => $userId,
        ]);
        $output->writeln(sprintf(
            '<info>Triggering scheduled run %d for agent %d...</info>',
            $run->id,
            $run->agent_id,
        ));

        $leaseOwner = 'server:housekeeping';
        $task = null;

        try {
            // Pass userId = the housekeeping caller so tasks.user_id = caller.
            // If the synchronous tick fails partway and the task is left in
            // RUNNING/QUEUED, only the caller's browser would try to tick it
            // (and the reaper would flip it after the lease expires). Other
            // group members' browsers do not race because their user_id filter
            // does not match.
            $task = $this->orchestrator->start(
                agentId: (int) $run->agent_id,
                userPrompt: $prompt,
                maxSteps: (int) $maxSteps,
                parentTaskId: null,
                runId: $run->id,
                userId: $userId,
            );

            // CAS-claim the row inside a transaction so two /housekeeping calls
            // (or a call racing with a browser tick on tasks.user_id = $userId)
            // can't both observe QUEUED + no live lease and flip the same row
            // to RUNNING. Without this claim `tick()` is a no-op on QUEUED rows
            // (Phase 1 always-QUEUE invariant).
            $leaseUntil = Carbon::now()->modify('+' . $tickLeaseSeconds . ' seconds');
            $claimed = Capsule::connection()->transaction(function () use ($task, $leaseOwner, $leaseUntil): ?Task {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $row = Task::where('id', $task->id)
                    ->where('status', 'QUEUED')
                    ->where(function ($q) use ($now): void {
                        $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
                    })
                    ->lockForUpdate()
                    ->first();
                if ($row === null) {
                    return null;
                }
                $row->status = 'RUNNING';
                $row->lease_owner = $leaseOwner;
                $row->lease_expires_at = $leaseUntil;
                $row->save();
                return $row;
            });
            if ($claimed === null) {
                $this->logger->warning('Scheduled-run tick skipped: claim lost', ['task_id' => $task->id]);
                return false;
            }
            $task = $claimed;

            $config = (new OrchestratorConfig())
                ->withLease($leaseOwner, $tickLeaseSeconds);

            $this->orchestrator->tick($task->id, $config);
        } catch (Throwable $e) {
            $this->logger->error('Scheduled run (client mode) failed', [
                'run_id'          => $run->id,
                'task_id'         => $task?->id,
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);
            $output->writeln(sprintf(
                '<error>Scheduled run %d failed: %s</error>',
                $run->id,
                $e->getMessage(),
            ));

            // Mirror TaskController::tick's exception path: flip to FAILED,
            // clear the lease, publish Mercure for the caller's UI.
            if ($task !== null) {
                Task::where('id', $task->id)
                    ->where('status', 'RUNNING')
                    ->update([
                        'status'           => 'FAILED',
                        'failure_reason'   => $e->getMessage(),
                        'error_code'       => 'UNKNOWN',
                        'error_message'    => $e->getMessage(),
                        'lease_owner'      => null,
                        'lease_expires_at' => null,
                    ]);
                $this->mercure->publish($task->id, $userId, [
                    'task_id'    => $task->id,
                    'status'     => 'FAILED',
                    'error_code' => 'UNKNOWN',
                ]);
            }

            // Symmetric with server-mode failure: mark SKIPPED, queue next
            // PENDING (recurring) or deactivate (one-shot).
            $completedAt = gmdate(self::DB_DATETIME_FORMAT);
            $nextDueAt = $this->computeNextDueAt($run);
            Capsule::connection()->transaction(function () use ($entry, $completedAt, $nextDueAt, $run): void {
                Capsule::table('scheduled_runs_next')
                    ->where('id', $entry->id)
                    ->update([
                        'status'       => ScheduledRunNext::STATUS_SKIPPED,
                        'completed_at' => $completedAt,
                    ]);
                if ($nextDueAt !== null) {
                    $this->insertRecurringEntry($run, $nextDueAt, $completedAt);
                    $this->updateRecurringRun($run, $completedAt, $nextDueAt);
                } else {
                    $this->deactivateRun($run, $completedAt);
                }
            });

            $this->lastProcessed = 0;
            return false;
        }

        $this->finalizeScheduledRun($run, $entry, $task);
        $this->lastProcessed = 1;
        return true;
    }

    /**
     * Atomically claim the next due scheduled run and resolve its run/agent.
     *
     * Uses SELECT … FOR UPDATE inside a transaction so the row we read is the
     * same row we mark CLAIMED. This avoids the prior read-after-write race
     * where a follow-up `WHERE status=CLAIMED ORDER BY due_at` could return a
     * stale CLAIMED row from a previous worker run that crashed between claim
     * and finalization — which dispatched the wrong agent.
     *
     * @return array{entry: object, run: ScheduledRun, agent: Agent}|null
     */
    private function claimNextScheduledRun(): ?array
    {
        $now = gmdate(self::DB_DATETIME_FORMAT);

        return Capsule::connection()->transaction(function () use ($now) {
            $entry = Capsule::table('scheduled_runs_next')
                ->where('status', ScheduledRunNext::STATUS_PENDING)
                ->where('due_at', '<=', $now)
                ->orderBy('due_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                return null;
            }

            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update([
                    'status'     => ScheduledRunNext::STATUS_CLAIMED,
                    'claimed_at' => $now,
                ]);

            return $this->resolveClaimedRun($entry);
        });
    }

    /**
     * Principal-scoped claim (replaces the pre-v0.18.0 user-scoped claim).
     * Returns the next due scheduled run whose agent is owned by any of the
     * given principals. Multiple callers' claims race-safely via lockForUpdate
     * + status flip; only one call across the whole install wins a given
     * scheduled_runs_next row.
     *
     * @param list<int> $principalIds  result of PrincipalResolver::visiblePrincipalIds()
     *
     * @return array{entry: object, run: ScheduledRun, agent: Agent}|null
     */
    private function claimNextScheduledRunForPrincipals(array $principalIds): ?array
    {
        if ($principalIds === []) {
            return null;
        }

        $now = gmdate(self::DB_DATETIME_FORMAT);

        return Capsule::connection()->transaction(function () use ($now, $principalIds) {
            $entry = Capsule::table('scheduled_runs_next')
                ->join('scheduled_runs', 'scheduled_runs.id', '=', 'scheduled_runs_next.scheduled_run_id')
                ->join('agents', 'agents.id', '=', 'scheduled_runs.agent_id')
                ->where('scheduled_runs_next.status', ScheduledRunNext::STATUS_PENDING)
                ->where('due_at', '<=', $now)
                ->whereIn('agents.principal_id', $principalIds)
                ->orderBy('due_at')
                ->orderBy('scheduled_runs_next.id')
                ->select('scheduled_runs_next.*')
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                return null;
            }

            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update([
                    'status'     => ScheduledRunNext::STATUS_CLAIMED,
                    'claimed_at' => $now,
                ]);

            return $this->resolveClaimedRun($entry);
        });
    }

    /**
     * @return array{entry: object, run: ScheduledRun, agent: Agent}|null
     */
    private function resolveClaimedRun(object $entry): ?array
    {
        /** @var ScheduledRun|null $run */
        $run = ScheduledRun::find((int) $entry->scheduled_run_id);

        if ($run === null || !$run->is_active) {
            $this->markScheduledRunSkipped((int) $entry->id);
            return null;
        }

        $agent = Agent::find($run->agent_id);
        if ($agent === null) {
            $this->logger->warning('Scheduled run has no agent, skipping', ['run_id' => $run->id]);
            $this->markScheduledRunSkipped((int) $entry->id);
            return null;
        }

        return ['entry' => $entry, 'run' => $run, 'agent' => $agent];
    }

    private function markScheduledRunSkipped(int $entryId): void
    {
        Capsule::table('scheduled_runs_next')
            ->where('id', $entryId)
            ->update(['status' => ScheduledRunNext::STATUS_SKIPPED]);
    }

    private function buildPrompt(ScheduledRun $run, ?AgentPromptTemplate $template, Agent $agent): string
    {
        if ($template !== null) {
            $variables = $template->variables ?? [];
            return $this->substituteVariables($template->prompt_template ?? '', $variables, $agent);
        }

        $prompt = $run->raw_prompt ?? '';
        return $this->substituteVariables($prompt, [], $agent);
    }

    private function resolveMaxSteps(ScheduledRun $run, ?AgentPromptTemplate $template, Agent $agent): int
    {
        if ($run->max_steps_override !== null) {
            return (int) $run->max_steps_override;
        }
        if ($template !== null && $template->max_steps !== null) {
            return (int) $template->max_steps;
        }
        return (int) $agent->max_steps;
    }

    /**
     * Mark the claimed entry DONE, schedule the next run, and publish notifications.
     */
    private function finalizeScheduledRun(ScheduledRun $run, object $entry, Task $task): void
    {
        $completedAt = gmdate(self::DB_DATETIME_FORMAT);
        $nextDueAt = $this->computeNextDueAt($run);

        // Atomically mark DONE and insert next PENDING entry (if recurring).
        // This prevents the gap where the old entry is CLAIMED/DONE but the next entry
        // was never created (e.g. process crash or signal interruption between steps).
        Capsule::connection()->transaction(function () use ($run, $entry, $completedAt, $nextDueAt): void {
            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update([
                    'status'       => ScheduledRunNext::STATUS_DONE,
                    'completed_at' => $completedAt,
                ]);

            if ($nextDueAt !== null) {
                $this->insertRecurringEntry($run, $nextDueAt, $completedAt);
                $this->updateRecurringRun($run, $completedAt, $nextDueAt);
            } else {
                $this->deactivateRun($run, $completedAt);
            }
        });

        $this->notificationService->notifyScheduledRunCompleted($run->id, $task);
        $this->notificationService->sendEmailForScheduledRun($task);

        $taskData = [
            'id'          => $task->id,
            'agent_id'    => $task->agent_id,
            'status'      => $task->status,
            'user_prompt' => $task->user_prompt,
        ];
        $this->mercure->publish($task->id, $task->user_id, $taskData);
    }

    private function computeNextDueAt(ScheduledRun $run): ?string
    {
        if ($run->cron_expression === null) {
            return null;
        }

        $nowInScheduleTz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($run->timezone));

        return (new CronExpression($run->cron_expression))
            ->getNextRunDate($nowInScheduleTz, 0, false, $run->timezone)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::DB_DATETIME_FORMAT);
    }

    private function insertRecurringEntry(ScheduledRun $run, string $nextDueAt, string $completedAt): void
    {
        // Drop any stale PENDING/CLAIMED entry at this exact (scheduled_run_id, due_at)
        // so the unique index cannot collide. The ->insertOrIgnore() below is the
        // dialect-portable safety net (INSERT OR IGNORE on SQLite, INSERT IGNORE on
        // MariaDB/MySQL) for any concurrent inserter the DELETE missed.
        Capsule::table('scheduled_runs_next')
            ->where('scheduled_run_id', $run->id)
            ->where('due_at', $nextDueAt)
            ->whereIn('status', [ScheduledRunNext::STATUS_PENDING, ScheduledRunNext::STATUS_CLAIMED])
            ->delete();

        Capsule::table('scheduled_runs_next')->insertOrIgnore([
            'scheduled_run_id' => $run->id,
            'due_at'           => $nextDueAt,
            'status'           => ScheduledRunNext::STATUS_PENDING,
            'created_at'       => $completedAt,
            'updated_at'       => $completedAt,
        ]);
    }

    private function updateRecurringRun(ScheduledRun $run, string $completedAt, string $nextDueAt): void
    {
        Capsule::table('scheduled_runs')
            ->where('id', $run->id)
            ->update([
                'last_run_at' => $completedAt,
                'next_run_at' => $nextDueAt,
            ]);
    }

    private function deactivateRun(ScheduledRun $run, string $completedAt): void
    {
        Capsule::table('scheduled_runs')
            ->where('id', $run->id)
            ->update([
                'last_run_at' => $completedAt,
                'next_run_at' => null,
                'is_active'   => 0,
            ]);
    }

    /**
     * Substitute {{variable}} placeholders in a template string.
     */
    private function substituteVariables(string $template, array $variables, ?Agent $agent = null): string
    {
        // Convert the JSON list to a map of key => default_value
        $defaults = [];
        foreach ($variables as $v) {
            if (isset($v['key'])) {
                $defaults[$v['key']] = $v['default_value'] ?? null;
            }
        }

        return preg_replace_callback('/\{\{(\w+)(?::([^}]*))?\}\}/', function (array $m) use ($defaults, $agent): string {
            return $this->resolveTemplateVariable($m, $defaults, $agent);
        }, $template);
    }

    /**
     * @param array<string, string|null> $defaults
     */
    private function resolveTemplateVariable(array $match, array $defaults, ?Agent $agent): string
    {
        $key = $match[1];
        $inlineDefault = $match[2] ?? null;

        $builtin = $this->resolveBuiltinDateVariable($key);
        if ($builtin !== null) {
            return $builtin;
        }

        if ($agent !== null && ($key === 'agent_name' || $key === 'user_name')) {
            return $key === 'agent_name'
                ? $agent->name
                : $this->resolveUserName($agent, $key);
        }

        return (isset($defaults[$key]) && $defaults[$key] !== '' ? (string) $defaults[$key] : null)
            ?? $inlineDefault
            ?? $match[0];
    }

    private function resolveBuiltinDateVariable(string $key): ?string
    {
        return match (true) {
            $key === 'current_date' || $key === 'date'        => date('Y-m-d'),
            $key === 'current_time' || $key === 'time'        => date('H:i'),
            $key === 'current_datetime' || $key === 'datetime' => date('Y-m-d\TH:i'),
            $key === 'day_of_week'    => date('l'),
            $key === 'day_of_month'   => date('j'),
            $key === 'month'          => date('F'),
            $key === 'year'           => date('Y'),
            default                   => null,
        };
    }

    private function resolveUserName(Agent $agent, string $key): string
    {
        $resolver = new PrincipalResolver();
        $ownerUserId = $resolver->ownerUserId((int) $agent->principal_id);
        if ($ownerUserId === null) {
            return $key;
        }
        $user = \Spora\Models\User::find($ownerUserId);
        return $user instanceof \Spora\Models\User ? ($user->username ?? $key) : $key;
    }
}
