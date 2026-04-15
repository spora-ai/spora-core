<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Cron\CronExpression;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Core\Database;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Models\Task;
use Spora\Services\NotificationService;
use Spora\Services\MercurePublisherInterface;
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

        if ($isScheduled && $isDaemon && extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use ($output): void {
                $this->shouldQuit = true;
                $this->releaseLock();
                $output->writeln('<info>Daemon shut down gracefully.</info>');
            });
            pcntl_signal(SIGINT, function () use ($output): void {
                $this->shouldQuit = true;
                $this->releaseLock();
                $output->writeln('<info>Daemon shut down gracefully.</info>');
            });

            // Single-instance lock — exit if another daemon is already running.
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

        while (!$this->shouldQuit && ($limit === 0 || $processed < $limit)) {
            // In daemon mode, periodically re-run the reaper so orphans from a concurrent
            // crash are cleaned up without waiting for the next restart.
            if ($isDaemon && (time() - $lastReapAt) >= self::REAP_INTERVAL_SECONDS) {
                $this->reapStaleTasks($output, $staleMinutes);
                $lastReapAt = time();
            }

            if ($isScheduled) {
                $this->processScheduledRuns($output, $processed);
                break;
            }

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

                    // Claim the task lock-safe before releasing the transaction.
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
                if ($isDaemon) {
                    usleep($sleep * 5); // back off on DB errors
                    continue;
                } else {
                    $this->releaseLock();
                    return Command::FAILURE;
                }
            }

            if ($task === null) {
                if ($isDaemon) {
                    usleep($sleep);
                    continue;
                }
                break;
            }

            $this->logger->info('Processing task', ['task_id' => $task->id]);
            $output->writeln(sprintf('<info>Processing task %d...</info>', $task->id));

            try {
                $this->orchestrator->tick($task->id);
                $this->logger->info('Task completed', ['task_id' => $task->id]);
            } catch (Throwable $e) {
                // tick() already marked the task FAILED and re-threw — log and move on.
                $this->logger->error('Task failed', [
                    'task_id' => $task->id,
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('<error>Task %d failed: %s</error>', $task->id, $e->getMessage()));
            }

            // Count all attempts toward the limit so --limit is a reliable cap on tasks touched,
            // not just tasks that succeeded. Failed tasks are already transitioned out of RUNNING.
            $processed++;

            // In daemon mode, memory limits can be breached after hours of processing.
            gc_collect_cycles();
        }

        $this->releaseLock();

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
            // Defensive re-check lock: skip if not active (already dispatched in a previous wake cycle)
            $run->refresh();
            if (!$run->is_active) {
                continue;
            }

            $agent = Agent::find($run->agent_id);
            if ($agent === null) {
                $this->logger->warning('Scheduled run has no agent, skipping', ['run_id' => $run->id]);
                continue;
            }

            // Determine prompt
            $prompt = '';
            if ($run->template_id !== null) {
                $template = AgentPromptTemplate::find($run->template_id);
                $variables = $template?->variables ?? [];
                $prompt = $this->substituteVariables($template?->prompt_template ?? '', $variables);
            } else {
                $prompt = $run->raw_prompt ?? '';
            }

            // Determine max_steps (priority: scheduled_run.max_steps_override > template.max_steps > agent.max_steps)
            $maxSteps = $run->max_steps_override
                ?? ($run->template_id !== null
                    ? (AgentPromptTemplate::find($run->template_id)?->max_steps ?? $agent->max_steps)
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
                $nextRun = $cron->getNextRunDate(new \DateTimeImmutable('now', new \DateTimeZone($run->timezone)));
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
    private function substituteVariables(string $template, array $variables): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $m) use ($variables): string {
            if ($m[1] === 'date') {
                return date('Y-m-d');
            }
            if ($m[1] === 'time') {
                return date('H:i');
            }
            if ($m[1] === 'datetime') {
                return date('c');
            }

            return $variables[$m[1]] ?? $m[0];
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
}
