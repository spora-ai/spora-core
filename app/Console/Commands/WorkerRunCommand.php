<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Core\Database;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Queue drain and persistent daemon worker.
 *
 * Cron mode (drains queue and exits): php bin/worker.php worker:run
 * Daemon mode (runs forever):         php bin/worker.php worker:run --daemon
 */
final class WorkerRunCommand extends Command
{
    /** Seconds between reaper passes in daemon mode. */
    private const REAP_INTERVAL_SECONDS = 300;

    private bool $shouldQuit = false;
    private mixed $lockFd = null;
    /** @var array<int, int> pid => taskId */
    /** @var array<int, resource> */
    private array $childProcs = [];

    /** Tracks how many scheduled runs were processed in the last processScheduledRuns() call (testing hook). */
    public int $lastScheduledProcessed = 0;

    public function __construct(
        private readonly Database            $database,
        private readonly OrchestratorInterface  $orchestrator,
        private readonly LoggerInterface        $logger,
        private readonly ContainerInterface     $container,
        private readonly MercurePublisherInterface $mercure,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct('worker:run');
    }

    protected function configure(): void
    {
        $this->setDescription('Drain QUEUED tasks, process scheduled runs, or run as a persistent daemon.');
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run as a persistent daemon that processes both QUEUED tasks and due scheduled runs on each iteration');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run once: process all due scheduled runs (and QUEUED tasks with --include-queue), then exit. Ideal for cron-driven deployments.');
        $this->addOption('include-queue', null, InputOption::VALUE_NONE, 'With --once: also drain the QUEUED task queue in the same run');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max QUEUED tasks to process per run (0 = unlimited)', '0');
        $this->addOption('sleep', 's', InputOption::VALUE_REQUIRED, 'Microseconds to sleep when the queue is empty', '500000');
        $this->addOption('stale-minutes', null, InputOption::VALUE_REQUIRED, 'Minutes after which a RUNNING task is orphaned and failed (0 = disabled, omit to use config/default)', '0');
        $this->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Max concurrent child processes (0 = unlimited, default: unlimited)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->database->bootDatabaseConnectionOnly();

        $isDaemon = (bool) $input->getOption('daemon');
        $isOnce = (bool) $input->getOption('once');
        $includeQueue = (bool) $input->getOption('include-queue');

        // --daemon and --once are mutually exclusive
        if ($isDaemon && $isOnce) {
            $output->writeln('<error>--daemon and --once cannot be used together.</error>');
            return Command::FAILURE;
        }

        // Resolve stale-minutes: CLI always wins; omit flag → config → default (60)
        $staleMinutesCli = $input->getOption('stale-minutes');
        $staleMinutes = $staleMinutesCli !== '0'
            ? (int) $staleMinutesCli
            : (int) ($this->container->get('config')['worker_stale_minutes'] ?? 60);

        $limit = (int) $input->getOption('limit');
        $sleep = (int) $input->getOption('sleep');

        // Resolution: CLI --workers N (>0) → cap at N; CLI absent/0 → config → 0 (unlimited)
        $workersCli = $input->getOption('workers');
        $maxWorkers = match (true) {
            $workersCli !== null && $workersCli !== '0' => (int) $workersCli,
            default => (int) ($this->container->get('config')['max_workers'] ?? 0),
        };

        // In daemon mode, always use a single-instance lock and set up graceful signal handling.
        if ($isDaemon && extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use ($output): void {
                $this->shouldQuit = true;
                $this->shutdownParent();
                $output->writeln('<info>Daemon shut down gracefully.</info>');
            });
            pcntl_signal(SIGINT, function () use ($output): void {
                $this->shouldQuit = true;
                $this->shutdownParent();
                $output->writeln('<info>Daemon shut down gracefully.</info>');
            });

            if (!$this->acquireLock($output)) {
                return Command::FAILURE;
            }
        }

        $processed  = 0;
        $lastReapAt = 0;

        if ($isDaemon) {
            $output->writeln('<info>Starting daemon worker. Press Ctrl+C to exit.</info>');
            $this->logger->info('Daemon worker started', [
                'stale_minutes' => $staleMinutes,
                'limit' => $limit,
            ]);
        } elseif ($isOnce) {
            $modeLabel = $includeQueue ? 'scheduled runs + QUEUED tasks' : 'scheduled runs';
            $output->writeln(sprintf('<info>Running in --once mode: processing %s then exiting.</info>', $modeLabel));
            $this->logger->info('Worker run (--once)', ['include_queue' => $includeQueue]);
        } else {
            $output->writeln('<info>Running in default (cron) mode: processing QUEUED tasks once then exiting.</info>');
            $this->logger->info('Worker run (default/cron)');
        }

        // Reap orphaned tasks at startup — covers any tasks left RUNNING by a previous crash.
        $this->reapStaleTasks($output, $staleMinutes);
        $lastReapAt = time();

        // Child processes give true parallelism for QUEUED tasks in daemon mode.
        // maxWorkers 0 = unlimited (spawn a child for every QUEUED task).
        // Scheduled runs are always processed synchronously in the parent.
        $useChildProcesses = $isDaemon;

        while (!$this->shouldQuit && ($limit === 0 || $processed < $limit)) {
            if ($isDaemon && (time() - $lastReapAt) >= self::REAP_INTERVAL_SECONDS) {
                $this->reapStaleTasks($output, $staleMinutes);
                $lastReapAt = time();
            }

            // Always process due scheduled runs in daemon mode and --once mode.
            // In --once mode this runs once per iteration (which is exactly one).
            if ($isDaemon || $isOnce) {
                $this->processScheduledRuns($output);
            }

            // Reap finished children on each iteration to avoid zombie procs.
            $this->reapChildren();

            // Process QUEUED tasks: always in daemon mode, only with --include-queue in --once mode.
            $shouldProcessQueue = $isDaemon || ($isOnce && $includeQueue);
            if ($shouldProcessQueue) {
                // Process retry queue first (retry tasks have retry_after timestamp)
                $this->processRetryQueue($output);

                if ($useChildProcesses) {
                    $this->processQueuedTaskWithChild($output, $maxWorkers, $sleep, $processed);
                } else {
                    $this->processQueuedTaskSync($output, $sleep, $processed);
                }
            }

            // In --once mode, exit after one iteration.
            if ($isOnce) {
                break;
            }

            // Daemon: sleep before next poll cycle.
            if ($isDaemon) {
                usleep($sleep);
            }

            gc_collect_cycles();
        }

        $this->shutdownParent();

        if ($isDaemon && $this->shouldQuit) {
            // Message already written by signal handler above
        } else {
            $output->writeln(sprintf('<info>Worker run complete. Processed %d task(s).</info>', $processed));
            $this->logger->info('Worker run complete', ['processed' => $processed]);
        }

        return Command::SUCCESS;
    }

    /**
     * Fetch and process all due scheduled runs.
     */
    private function processScheduledRuns(OutputInterface $output): void
    {
        $processed = 0;
        $now = date('Y-m-d H:i:s');

        // Atomic claim: UPDATE ... WHERE status = 'PENDING' AND due_at <= $now
        // Works atomically in SQLite since the WHERE clause is evaluated at write time.
        $claimed = Capsule::table('scheduled_runs_next')
            ->where('status', ScheduledRunNext::STATUS_PENDING)
            ->where('due_at', '<=', $now)
            ->limit(1)
            ->update([
                'status'     => ScheduledRunNext::STATUS_CLAIMED,
                'claimed_at' => $now,
            ]);

        if ($claimed === 0) {
            return; // Nothing due right now
        }

        // Re-read the claimed entry
        $entry = Capsule::table('scheduled_runs_next')
            ->where('status', ScheduledRunNext::STATUS_CLAIMED)
            ->where('due_at', '<=', $now)
            ->orderBy('due_at')
            ->first();

        if ($entry === null) {
            return;
        }

        /** @var ScheduledRun|null $run */
        $run = ScheduledRun::find((int) $entry->scheduled_run_id);

        if ($run === null || !$run->is_active) {
            // Mark as SKIPPED if the schedule was deactivated or deleted
            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update(['status' => ScheduledRunNext::STATUS_SKIPPED]);
            return;
        }

        $agent = Agent::find($run->agent_id);
        if ($agent === null) {
            $this->logger->warning('Scheduled run has no agent, skipping', ['run_id' => $run->id]);
            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update(['status' => ScheduledRunNext::STATUS_SKIPPED]);
            return;
        }

        $template = null;
        if ($run->template_id !== null) {
            $template = AgentPromptTemplate::find($run->template_id);
        }

        // Determine prompt
        $prompt = '';
        if ($template !== null) {
            $variables = $template->variables ?? [];
            $prompt = $this->substituteVariables($template->prompt_template ?? '', $variables, $agent);
        } else {
            $prompt = $run->raw_prompt ?? '';
            $prompt = $this->substituteVariables($prompt, [], $agent);
        }

        // Determine max_steps (priority: scheduled_run.max_steps_override > template.max_steps > agent.max_steps)
        $maxSteps = $run->max_steps_override
            ?? ($template !== null
                ? ($template->max_steps ?? $agent->max_steps)
                : $agent->max_steps);

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
            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update(['status' => ScheduledRunNext::STATUS_SKIPPED]);
            $output->writeln(sprintf('<error>Scheduled run %d failed: %s</error>', $run->id, $e->getMessage()));
            return;
        }

        $completedAt = date('Y-m-d H:i:s');

        // Compute next_due_at BEFORE the transaction.
        // Use wall-clock now as cron reference to avoid the same-day skip: when
        // last_run_at is just before the scheduled time (e.g. 06:58 UTC for a 07:10
        // UTC schedule), getNextRunDate(last_run_at) returns the same day's 07:10
        // which is already past. Using now as reference always yields the next future
        // occurrence. last_run_at is still tracked separately for historical accuracy.
        $nextDueAt = null;
        if ($run->cron_expression !== null) {
            $nowInScheduleTz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($run->timezone));

            $nextDueAt = (new CronExpression($run->cron_expression))
                ->getNextRunDate($nowInScheduleTz, 0, false, $run->timezone)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }

        // Atomically: mark current entry DONE + insert next PENDING entry (if recurring).
        // This prevents the gap where the old entry is CLAIMED/DONE but the next entry
        // was never created (e.g. process crash or signal interruption between steps).
        Capsule::connection()->transaction(function () use ($run, $entry, $completedAt, $nextDueAt): void {
            // Mark current PENDING entry as DONE
            Capsule::table('scheduled_runs_next')
                ->where('id', $entry->id)
                ->update([
                    'status'       => ScheduledRunNext::STATUS_DONE,
                    'completed_at' => $completedAt,
                ]);

            if ($nextDueAt !== null) {
                // Remove any stale PENDING/CLAIMED entry for the same due_at so the
                // INSERT below does not conflict on the unique (scheduled_run_id, due_at) index.
                Capsule::table('scheduled_runs_next')
                    ->where('scheduled_run_id', $run->id)
                    ->where('due_at', $nextDueAt)
                    ->whereIn('status', [ScheduledRunNext::STATUS_PENDING, ScheduledRunNext::STATUS_CLAIMED])
                    ->delete();

                // Use INSERT OR IGNORE as a safety net: if the DELETE above didn't catch a
                // stale entry (e.g. race with another worker), the unique constraint
                // violation is silently ignored rather than crashing the whole run.
                Capsule::connection()->statement(
                    "INSERT OR IGNORE INTO scheduled_runs_next (scheduled_run_id, due_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
                    [$run->id, $nextDueAt, ScheduledRunNext::STATUS_PENDING, $completedAt, $completedAt],
                );

                Capsule::table('scheduled_runs')
                    ->where('id', $run->id)
                    ->update([
                        'last_run_at' => $completedAt,
                        'next_run_at' => $nextDueAt,
                    ]);
            } else {
                // One-shot: update last_run_at, clear next_run_at, deactivate
                Capsule::table('scheduled_runs')
                    ->where('id', $run->id)
                    ->update([
                        'last_run_at' => $completedAt,
                        'next_run_at' => null,
                        'is_active'   => 0,
                    ]);
            }
        });

        $this->notificationService->notifyScheduledRunCompleted($run->id, $task);

        // Send e-mail notification if enabled
        $this->notificationService->sendEmailForScheduledRun($task);

        // Publish Mercure update
        $taskData = [
            'id'          => $task->id,
            'agent_id'    => $task->agent_id,
            'status'      => $task->status,
            'user_prompt' => $task->user_prompt,
        ];
        $this->mercure->publish($task->id, $task->user_id, $taskData);

        $processed++;
        $this->lastScheduledProcessed = 1;
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
            $key = $m[1];
            $inlineDefault = $m[2] ?? null;

            if ($key === 'current_date' || $key === 'date') {
                return date('Y-m-d');
            }
            if ($key === 'current_time' || $key === 'time') {
                return date('H:i');
            }
            if ($key === 'current_datetime' || $key === 'datetime') {
                return date('Y-m-d\TH:i');
            }
            if ($key === 'agent_name' && $agent !== null) {
                return $agent->name;
            }
            if ($key === 'user_name' && $agent !== null) {
                $user = \Spora\Models\User::find($agent->user_id);
                return $user instanceof \Spora\Models\User ? ($user->username ?? $key) : $key;
            }
            if ($key === 'day_of_week') {
                return date('l');
            }
            if ($key === 'day_of_month') {
                return date('j');
            }
            if ($key === 'month') {
                return date('F');
            }
            if ($key === 'year') {
                return date('Y');
            }

            if (isset($defaults[$key]) && $defaults[$key] !== '') {
                return $defaults[$key];
            }

            return $inlineDefault ?? $m[0];
        }, $template);
    }

    /**
     * Acquire an exclusive flock() lock to ensure only one daemon runs at a time.
     * Returns false if the lock cannot be acquired (another instance is running).
     */
    private function acquireLock(OutputInterface $output): bool
    {
        $lockFile = rtrim(BASE_PATH . '/storage', '/') . '/spora-worker.lock';

        $this->lockFd = fopen($lockFile, 'c');
        if (!flock($this->lockFd, LOCK_EX | LOCK_NB)) {
            $output->writeln('<error>Another worker instance is already running. Exiting.</error>');
            $this->logger->warning('Could not acquire worker lock — another instance is running');
            fclose($this->lockFd);
            $this->lockFd = null;
            return false;
        }

        // Write PID for debugging/observability
        file_put_contents($lockFile, (string) getmypid());
        return true;
    }

    /**
     * Release the flock lock if held.
     */
    private function releaseLock(): void
    {
        if ($this->lockFd !== null) {
            flock($this->lockFd, LOCK_UN);
            fclose($this->lockFd);
            $this->lockFd = null;
        }
    }

    /**
     * Sweep any tasks stuck in RUNNING for longer than $staleMinutes and mark them FAILED.
     *
     * These orphans are produced when a worker process is killed ungracefully (OOM, server
     * reboot, SIGKILL) before it can clean up. The reaper runs once at startup and
     * periodically in daemon mode so the system self-corrects without manual intervention.
     *
     * The timeout should exceed the worst-case LLM round-trip time for your provider to
     * avoid false positives on slow but genuinely in-progress tasks.
     */
    private function reapStaleTasks(OutputInterface $output, int $staleMinutes): void
    {
        if ($staleMinutes <= 0) {
            return; // Reaping disabled
        }

        $cutoff = date('Y-m-d H:i:s', time() - $staleMinutes * 60);

        // Capture IDs before UPDATE so we can notify after
        $orphanedIds = Task::where('status', 'RUNNING')
            ->where('updated_at', '<', $cutoff)
            ->pluck('id')
            ->toArray();

        if ($orphanedIds === []) {
            return;
        }

        $updated = Task::whereIn('id', $orphanedIds)->update([
            'status'         => 'FAILED',
            'failure_reason' => sprintf(
                'Task orphaned: still RUNNING after %d minutes — worker process likely crashed or was restarted.',
                $staleMinutes,
            ),
            'error_code'    => 'ORPHANED',
            'error_message' => 'The task was interrupted. Click Retry to start a fresh attempt.',
        ]);

        if ($updated > 0) {
            $this->logger->warning('Reaped orphaned RUNNING tasks', [
                'count' => $updated,
                'stale_minutes' => $staleMinutes,
            ]);
            $output->writeln(sprintf(
                '<comment>Reaped %d orphaned RUNNING task(s) (idle > %d min).</comment>',
                $updated,
                $staleMinutes,
            ));

            // Batch-fetch + send orphaned notifications
            $orphaned = Task::findMany($orphanedIds);
            foreach ($orphaned as $task) {
                $this->notificationService->notifyTaskOrphaned($task);
            }
        }
    }

    /**
     * Claim and process a single QUEUED task synchronously in the parent process.
     * Used when --workers is 0 (single-threaded mode).
     */
    private function processQueuedTaskSync(OutputInterface $output, int $sleep, int &$processed): void
    {
        try {
            $task = Capsule::connection()->transaction(function (): ?Task {
                /** @var Task|null $task */
                $task = Task::where('status', 'QUEUED')
                    ->where('retry_of_task_id', null) // skip retry tasks (handled by processRetryQueue)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($task === null) {
                    return null;
                }

                $task->status = 'RUNNING';
                $task->save();

                return $task;
            });
        } catch (Throwable $e) {
            $this->logger->error('Database error during task claim', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $output->writeln(sprintf('<error>Database error: %s</error>', $e->getMessage()));
            usleep($sleep * 5);
            return;
        }

        if ($task === null) {
            usleep($sleep);
            return;
        }

        $this->logger->info('Processing task', ['task_id' => $task->id]);
        $output->writeln(sprintf('<info>Processing task %d...</info>', $task->id));

        // Publish RUNNING state to Mercure so frontend sees the transition immediately
        $this->mercure->publish($task->id, $task->user_id, [
            'task_id'  => $task->id,
            'status'   => 'RUNNING',
        ]);

        try {
            $this->orchestrator->tick($task->id);
            // Notification is sent by Orchestrator.tick() — do not duplicate here.
            $this->logger->info('Task completed', ['task_id' => $task->id]);
        } catch (Throwable $e) {
            // Notification is sent by Orchestrator.tick() catch block — do not duplicate here.
            $this->logger->error('Task failed', [
                'task_id' => $task->id,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $output->writeln(sprintf('<error>Task %d failed: %s</error>', $task->id, $e->getMessage()));
        }

        $processed++;
    }

    /**
     * Spawn child processes for all available QUEUED tasks.
     * The parent claims each task (QUEUED → RUNNING) before spawning its child.
     * The child skips re-claiming since the parent already did it.
     * Loops until either maxWorkers (0 = unlimited) is reached or no more QUEUED tasks exist.
     */
    private function processQueuedTaskWithChild(OutputInterface $output, int $maxWorkers, int $sleep, int &$processed): void
    {
        while ($maxWorkers === 0 || count($this->childProcs) < $maxWorkers) {
            $task = Capsule::connection()->transaction(function (): ?Task {
                /** @var Task|null $task */
                $task = Task::where('status', 'QUEUED')
                    ->where('retry_of_task_id', null)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($task === null) {
                    return null;
                }

                $task->status = 'RUNNING';
                $task->save();

                return $task;
            });

            if ($task === null) {
                break;
            }

            // Publish RUNNING state to Mercure so the frontend sees the transition
            $this->mercure->publish($task->id, $task->user_id, [
                'task_id' => $task->id,
                'status'  => 'RUNNING',
            ]);

            $pid = $this->spawnChild($task->id);
            if ($pid === null) {
                $task->status = 'QUEUED';
                $task->save();
                $this->logger->warning('Failed to spawn child for task, reverting to QUEUED', ['task_id' => $task->id]);
                continue;
            }

            $output->writeln(sprintf('<info>Spawned child %d for task %d</info>', $pid, $task->id));
            $processed++;
        }
    }

    /**
     * Spawn a child process to handle a single task.
     * The child inherits stdout/stderr from the parent — no pipe management needed.
     */
    private function spawnChild(int $taskId): ?int
    {
        $php = PHP_BINARY;
        $bin = BASE_PATH . '/bin/spora';
        $cmd = [$php, $bin, 'task:run', (string) $taskId];

        $proc = proc_open($cmd, [], $pipes);
        if (!is_resource($proc)) {
            return null;
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_get_status($proc);
        $pid = $status['pid'];

        $this->childProcs[$pid] = $proc;

        return $pid;
    }

    /**
     * Reap any child processes that have exited (non-blocking).
     */
    private function reapChildren(): void
    {
        foreach ($this->childProcs as $pid => $proc) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                proc_close($proc);
                unset($this->childProcs[$pid]);
            }
        }
    }

    /**
     * Process tasks that are waiting for a retry after a failure.
     *
     * Retry tasks have retry_of_task_id (→ root original), retry_count, and
     * retry_after (UTC timestamp). When retry_after <= now, the task is picked
     * up, marked RUNNING, notified, and processed via tick().
     *
     * All retry tasks always link to the ROOT original task (not the immediate
     * parent), enabling single-query cancellation: WHERE retry_of_task_id = root
     * AND retry_count >= N.
     */
    private function processRetryQueue(OutputInterface $output): void
    {
        $now = date('Y-m-d H:i:s');

        // Single query: fetch retry tasks JOINed with root task status.
        // Filters out retry tasks whose root is CANCELLED.
        $retryTasks = Capsule::connection()->select("
            SELECT t.id, t.retry_of_task_id, t.retry_count, o.status AS original_status, o.agent_id
            FROM tasks t
            JOIN tasks o ON o.id = t.retry_of_task_id
            WHERE t.status = 'QUEUED'
              AND t.retry_after IS NOT NULL
              AND t.retry_after <= ?
              AND o.status != 'CANCELLED'
        ", [$now]);

        if ($retryTasks === []) {
            return;
        }

        $taskIds = array_column($retryTasks, 'id');

        // Single batch UPDATE: mark all due retry tasks as RUNNING
        Capsule::table('tasks')
            ->whereIn('id', $taskIds)
            ->update(['status' => 'RUNNING']);

        // Batch-fetch tasks + agents (2 queries regardless of N)
        /** @var Task[] $allRetryTasks */
        $allRetryTasks = Task::findMany($taskIds);
        $agentIds = array_unique(array_column($retryTasks, 'agent_id'));
        /** @var Agent[] $allAgents */
        $allAgents = Agent::findMany($agentIds);
        $agentMaxRetries = collect($allAgents)->keyBy->id->map(fn(Agent $a) => $a->max_retries)->toArray();

        foreach ($allRetryTasks as $retryTask) {
            // Publish RUNNING state to Mercure
            $this->mercure->publish($retryTask->id, $retryTask->user_id, [
                'task_id' => $retryTask->id,
                'status'  => 'RUNNING',
            ]);

            $retryCount = (int) $retryTask->retry_count;
            $maxRetries = $agentMaxRetries[$retryTask->agent_id] ?? 0;

            $this->notificationService->notifyTaskRetrying($retryTask, $retryCount, $maxRetries);

            $this->orchestrator->tick((int) $retryTask->id);
        }
    }

    /**
     * Gracefully shut down the parent and all child processes.
     */
    private function shutdownParent(): void
    {
        foreach ($this->childProcs as $proc) {
            proc_terminate($proc);
        }

        $timeout = 30_000_000;
        $start = hrtime(true);
        while (count($this->childProcs) > 0 && (hrtime(true) - $start) < $timeout) {
            $this->reapChildren();
            if (count($this->childProcs) > 0) {
                usleep(100_000);
            }
        }

        foreach ($this->childProcs as $proc) {
            proc_close($proc);
        }
        $this->childProcs = [];

        $this->releaseLock();
    }
}
