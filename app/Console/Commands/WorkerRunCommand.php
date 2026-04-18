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
        $this->setDescription('Drain QUEUED tasks, or run as a persistent daemon.');
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run as a persistent daemon rather than exiting when empty');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max tasks to process per run (0 = unlimited)', '0');
        $this->addOption('sleep', 's', InputOption::VALUE_REQUIRED, 'Microseconds to sleep when the queue is empty', '500000');
        $this->addOption('stale-minutes', null, InputOption::VALUE_REQUIRED, 'Minutes after which a RUNNING task is orphaned and failed (0 = disabled, omit to use config/default)', '0');
        $this->addOption('scheduled', null, InputOption::VALUE_NONE, 'Process due scheduled runs instead of the QUEUED task queue');
        $this->addOption('workers', 'w', InputOption::VALUE_REQUIRED, 'Max concurrent child processes in daemon mode (0 = unlimited, default: 0)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->database->bootDatabaseConnectionOnly();

        $isDaemon = (bool) $input->getOption('daemon');

        // Resolve stale-minutes: CLI always wins; omit flag → config → default (60)
        $staleMinutesCli = $input->getOption('stale-minutes');
        $staleMinutes = $staleMinutesCli !== '0'
            ? (int) $staleMinutesCli
            : (int) ($this->container->get('config')['worker_stale_minutes'] ?? 60);

        $limit = (int) $input->getOption('limit');
        $sleep = (int) $input->getOption('sleep');
        $isScheduled = (bool) $input->getOption('scheduled');
        $maxWorkers = (int) $input->getOption('workers');

        // In daemon mode (with or without --scheduled), always use a single-instance lock
        // and set up graceful signal handling.
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
        }

        // Reap orphaned tasks at startup — covers any tasks left RUNNING by a previous crash.
        $this->reapStaleTasks($output, $staleMinutes);
        $lastReapAt = time();

        // Decide which processing mode to use.
        // Child processes (--workers > 0) give true parallelism for regular QUEUED tasks.
        // --scheduled runs are always processed synchronously in the parent.
        $useChildProcesses = $isDaemon && $maxWorkers > 0 && !$isScheduled;

        while (!$this->shouldQuit && ($limit === 0 || $processed < $limit)) {
            if ($isDaemon && (time() - $lastReapAt) >= self::REAP_INTERVAL_SECONDS) {
                $this->reapStaleTasks($output, $staleMinutes);
                $lastReapAt = time();
            }

            if ($isScheduled) {
                $this->processScheduledRuns($output, $processed);
                break;
            }

            // Reap finished children on each iteration to avoid zombie procs.
            $this->reapChildren();

            if ($useChildProcesses) {
                $this->processQueuedTaskWithChild($output, $maxWorkers, $sleep, $processed);
            } else {
                $this->processQueuedTaskSync($output, $sleep, $processed);
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
    private function processScheduledRuns(OutputInterface $output, int &$processed): void
    {
        $now = date('Y-m-d H:i:s');

        $dueRuns = ScheduledRun::where('is_active', true)
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->get();

        foreach ($dueRuns as $run) {
            // N+1 deliberately retained: This call to $run->refresh() creates an N+1 query pattern,
            // but it is an intentional trade-off to handle multi-worker concurrency. Because Spora
            // uses SQLite (which lacks robust row-level locking like SELECT ... FOR UPDATE),
            // this refresh prevents the same task from being executed multiple times if two
            // background workers pick up the same batch simultaneously.
            $run->refresh();
            if (!$run->is_active) {
                continue;
            }

            $agent = Agent::find($run->agent_id);
            if ($agent === null) {
                $this->logger->warning('Scheduled run has no agent, skipping', ['run_id' => $run->id]);
                continue;
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
                $task = $this->orchestrator->start((int) $run->agent_id, $prompt, (int) $maxSteps);
            } catch (Throwable $e) {
                $this->logger->error('Scheduled run failed', [
                    'run_id' => $run->id,
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('<error>Scheduled run %d failed: %s</error>', $run->id, $e->getMessage()));
                continue;
            }

            $this->notificationService->notifyScheduledRunCompleted($run->id, $task);

            // Update last_run_at
            Capsule::table('scheduled_runs')
                ->where('id', $run->id)
                ->update(['last_run_at' => date('Y-m-d H:i:s')]);

            // Recompute next_run_at for recurring runs
            if ($run->cron_expression !== null) {
                $cron = new CronExpression($run->cron_expression);
                $nextRun = $cron->getNextRunDate(new DateTimeImmutable('now', new DateTimeZone($run->timezone)));
                Capsule::table('scheduled_runs')
                    ->where('id', $run->id)
                    ->update(['next_run_at' => $nextRun->format('Y-m-d H:i:s')]);
            }

            // Publish Mercure update
            $taskData = [
                'id'          => $task->id,
                'agent_id'    => $task->agent_id,
                'status'      => $task->status,
                'user_prompt' => $task->user_prompt,
            ];
            $this->mercure->publish($task->id, $taskData);

            $processed++;
        }
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

        $reaped = Task::where('status', 'RUNNING')
            ->where('updated_at', '<', $cutoff)
            ->update([
                'status'         => 'FAILED',
                'failure_reason' => sprintf(
                    'Task orphaned: still RUNNING after %d minutes — worker process likely crashed or was restarted.',
                    $staleMinutes,
                ),
            ]);

        if ($reaped > 0) {
            $this->logger->warning('Reaped orphaned RUNNING tasks', [
                'count' => $reaped,
                'stale_minutes' => $staleMinutes,
            ]);
            $output->writeln(sprintf(
                '<comment>Reaped %d orphaned RUNNING task(s) (idle > %d min).</comment>',
                $reaped,
                $staleMinutes,
            ));
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

        try {
            $this->orchestrator->tick($task->id);
            $this->logger->info('Task completed', ['task_id' => $task->id]);
        } catch (Throwable $e) {
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
     * Claim a QUEUED task and spawn a child process via proc_open() to handle it.
     * The parent monitors children non-blockingly and reaps them on each iteration.
     */
    private function processQueuedTaskWithChild(OutputInterface $output, int $maxWorkers, int $sleep, int &$processed): void
    {
        $activeChildren = count($this->childProcs);
        if ($maxWorkers > 0 && $activeChildren >= $maxWorkers) {
            usleep($sleep);
            return;
        }

        $task = Capsule::connection()->transaction(function (): ?Task {
            /** @var Task|null $task */
            $task = Task::where('status', 'QUEUED')
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
            usleep($sleep);
            return;
        }

        $pid = $this->spawnChild($task->id);
        if ($pid === null) {
            $task->status = 'QUEUED';
            $task->save();
            $this->logger->warning('Failed to spawn child for task, reverting to QUEUED', ['task_id' => $task->id]);
            usleep($sleep);
            return;
        }

        $output->writeln(sprintf('<info>Spawned child %d for task %d</info>', $pid, $task->id));
        $processed++;
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
