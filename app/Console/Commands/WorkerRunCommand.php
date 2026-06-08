<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Console\Worker\WorkerQueueProcessor;
use Spora\Console\Worker\WorkerReaper;
use Spora\Console\WorkerLoopOptions;
use Spora\Core\Database;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue drain and persistent daemon worker.
 *
 * Cron mode (drains queue and exits): php bin/worker.php worker:run
 * Daemon mode (runs forever):         php bin/worker.php worker:run --daemon
 *
 * Delegates the actual work to:
 *   - WorkerReaper:          orphan task reaper
 *   - WorkerQueueProcessor:  queue/retry/child-process lifecycle
 *   - ScheduledRunProcessor: scheduled-run claim, dispatch, finalize
 */
final class WorkerRunCommand extends Command
{
    private const REAP_INTERVAL_SECONDS = 300;

    private bool $shouldQuit = false;
    private mixed $lockFd = null;

    /** Tracks how many scheduled runs were processed in the last processScheduledRuns() call (testing hook). */
    public int $lastScheduledProcessed = 0;

    private readonly WorkerReaper $reaper;
    private readonly WorkerQueueProcessor $queueProcessor;
    private readonly ScheduledRunProcessor $scheduledRunProcessor;

    public function __construct(
        private readonly Database            $database,
        OrchestratorInterface                $orchestrator,
        private readonly LoggerInterface        $logger,
        private readonly ContainerInterface     $container,
        MercurePublisherInterface            $mercure,
        NotificationService                  $notificationService,
    ) {
        $this->reaper = new WorkerReaper($logger, $notificationService);
        $this->queueProcessor = new WorkerQueueProcessor(
            $orchestrator,
            $logger,
            $mercure,
            $notificationService,
        );
        $this->scheduledRunProcessor = new ScheduledRunProcessor(
            $orchestrator,
            $logger,
            $mercure,
            $notificationService,
        );
        parent::__construct('worker:run');
    }

    protected function configure(): void
    {
        $this->setDescription('Drain QUEUED tasks, process scheduled runs, or run as a persistent daemon.');
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run as a persistent daemon (default when no flag is given).');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run once: process all due scheduled runs (and QUEUED tasks with --include-queue), then exit. Ideal for cron-driven deployments.');
        $this->addOption('include-queue', null, InputOption::VALUE_NONE, 'With --once: also drain the QUEUED task queue in the same run');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max QUEUED tasks to process per run (0 = unlimited)', '0');
        $this->addOption('sleep', 's', InputOption::VALUE_REQUIRED, 'Microseconds to sleep when the queue is empty', '500000');
        $this->addOption('stale-minutes', null, InputOption::VALUE_REQUIRED, 'Minutes before a RUNNING task is orphaned (0 = disabled, omit to use config/default/60)', null);
        $this->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Max concurrent child processes (0 = unlimited, default: unlimited)', null);
        $this->addOption('reap-only', null, InputOption::VALUE_NONE, 'Reap orphaned RUNNING tasks once, then exit. No queue processing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->database->bootDatabaseConnectionOnly();

        $isDaemon = (bool) $input->getOption('daemon');
        $isOnce = (bool) $input->getOption('once');
        $includeQueue = (bool) $input->getOption('include-queue');
        $isReapOnly = (bool) $input->getOption('reap-only');

        $validationError = $this->validateModeFlags($output, $isDaemon, $isOnce, $isReapOnly);
        if ($validationError !== null) {
            return $validationError;
        }

        $isDaemon = $isDaemon || (!$isOnce && !$isReapOnly);

        // Resolve stale-minutes: CLI always wins; omit flag → config → default (60)
        // Explicit 0 from CLI means "disabled" (never reap).
        $staleMinutesRaw = $input->getOption('stale-minutes');
        $staleMinutes = (int) ($staleMinutesRaw !== null
            ? $staleMinutesRaw
            : ($this->container->get('config')['worker_stale_minutes'] ?? 60));

        $limit = (int) $input->getOption('limit');
        $sleep = (int) $input->getOption('sleep');

        // Resolution: CLI --workers N (>0) → cap at N; CLI absent/0 → config → 0 (unlimited)
        $workersCli = $input->getOption('workers');
        $maxWorkers = match (true) {
            $workersCli !== null && $workersCli !== '0' => (int) $workersCli,
            default => (int) ($this->container->get('config')['max_workers'] ?? 0),
        };

        if (!$isReapOnly && !$this->acquireLock($output)) {
            return Command::FAILURE;
        }

        if ($isDaemon && extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use ($output): void {
                $this->shouldQuit = true;
                $this->queueProcessor->shutdownParent();
                $output->writeln('<info>Daemon shut down gracefully.</info>');
            });
            pcntl_signal(SIGINT, function () use ($output): void {
                $this->shouldQuit = true;
                $this->queueProcessor->shutdownParent();
                $output->writeln('<info>Daemon shut down gracefully.</info>');
            });
        }

        $this->announceMode($output, $isDaemon, $isOnce, $includeQueue, $staleMinutes, $limit);

        $processed = 0;
        $this->reaper->reapStaleTasks($output, $staleMinutes);

        if ($isReapOnly) {
            $output->writeln('<info>Orphan reaping complete. Exiting.</info>');
            return Command::SUCCESS;
        }

        $options = new WorkerLoopOptions(
            isDaemon: $isDaemon,
            isOnce: $isOnce,
            includeQueue: $includeQueue,
            useChildProcesses: $isDaemon,
            maxWorkers: $maxWorkers,
            sleep: $sleep,
            staleMinutes: $staleMinutes,
        );

        $this->runWorkerLoop($options, $output, $limit, $processed);

        $this->queueProcessor->shutdownParent();
        $this->releaseLock();

        if (!$isDaemon || !$this->shouldQuit) {
            $output->writeln(sprintf('<info>Worker run complete. Processed %d task(s).</info>', $processed));
            $this->logger->info('Worker run complete', ['processed' => $processed]);
        }

        return Command::SUCCESS;
    }

    /**
     * Validate that mutually-exclusive mode flags were not combined.
     * Returns Command::FAILURE if incompatible flags are set, otherwise null.
     */
    private function validateModeFlags(OutputInterface $output, bool $isDaemon, bool $isOnce, bool $isReapOnly): ?int
    {
        $conflict = $this->detectModeFlagConflict($isDaemon, $isOnce, $isReapOnly);
        if ($conflict === null) {
            return null;
        }

        $output->writeln("<error>{$conflict}</error>");

        return Command::FAILURE;
    }

    private function detectModeFlagConflict(bool $isDaemon, bool $isOnce, bool $isReapOnly): ?string
    {
        return match (true) {
            $isDaemon && $isOnce       => '--daemon and --once cannot be used together.',
            $isDaemon && $isReapOnly   => '--daemon and --reap-only cannot be used together.',
            $isOnce && $isReapOnly     => '--once and --reap-only cannot be used together.',
            default                    => null,
        };
    }

    private function announceMode(
        OutputInterface $output,
        bool $isDaemon,
        bool $isOnce,
        bool $includeQueue,
        int $staleMinutes,
        int $limit,
    ): void {
        if ($isDaemon) {
            $output->writeln('<info>Starting daemon worker. Press Ctrl+C to exit.</info>');
            $this->logger->info('Daemon worker started', [
                'stale_minutes' => $staleMinutes,
                'limit' => $limit,
            ]);
            return;
        }
        if ($isOnce) {
            $modeLabel = $includeQueue ? 'scheduled runs + QUEUED tasks' : 'scheduled runs';
            $output->writeln(sprintf('<info>Running in --once mode: processing %s then exiting.</info>', $modeLabel));
            $this->logger->info('Worker run (--once)', ['include_queue' => $includeQueue]);
            return;
        }
        $output->writeln('<info>Running in --reap-only mode: reaping orphaned tasks then exiting.</info>');
        $this->logger->info('Worker run (--reap-only)');
    }

    /**
     * Run the main worker loop, handling periodic orphan reaping, scheduled
     * runs, child reaping, queue processing, and the once-mode break.
     */
    private function runWorkerLoop(
        WorkerLoopOptions $options,
        OutputInterface $output,
        int $limit,
        int &$processed,
    ): void {
        $lastReapAt = time();

        while (!$this->shouldQuit && $this->canProcessMore($limit, $processed)) {
            $lastReapAt = $this->runLoopIteration(
                $options,
                $output,
                $lastReapAt,
                $processed,
            );

            if ($this->endOfIteration($options->isOnce, $options->sleep)) {
                break;
            }

            gc_collect_cycles();
        }
    }

    private function canProcessMore(int $limit, int $processed): bool
    {
        return $limit === 0 || $processed < $limit;
    }

    private function isReapDue(int $lastReapAt): bool
    {
        return (time() - $lastReapAt) >= self::REAP_INTERVAL_SECONDS;
    }

    private function shouldProcessQueue(bool $isDaemon, bool $isOnce, bool $includeQueue): bool
    {
        return $isDaemon || ($isOnce && $includeQueue);
    }

    /**
     * Perform one tick of the worker loop: optional reap, scheduled runs,
     * child reaping, then queue processing. Returns the updated reap marker.
     */
    private function runLoopIteration(
        WorkerLoopOptions $options,
        OutputInterface $output,
        int $lastReapAt,
        int &$processed,
    ): int {
        $lastReapAt = $this->maybeReapStale($options, $output, $lastReapAt);

        if ($options->isDaemon || $options->isOnce) {
            $this->scheduledRunProcessor->process($output);
            $this->lastScheduledProcessed = $this->scheduledRunProcessor->lastProcessed;
        }

        $this->queueProcessor->reapChildren();

        if ($this->shouldProcessQueue($options->isDaemon, $options->isOnce, $options->includeQueue)) {
            $this->queueProcessor->processRetryQueue();

            if ($options->useChildProcesses) {
                $this->queueProcessor->processQueuedTaskWithChild($output, $options->maxWorkers, $processed);
            } else {
                $this->queueProcessor->processQueuedTaskSync($output, $options->sleep, $processed);
            }
        }

        return $lastReapAt;
    }

    private function maybeReapStale(WorkerLoopOptions $options, OutputInterface $output, int $lastReapAt): int
    {
        if ($options->isDaemon && $this->isReapDue($lastReapAt)) {
            $this->reaper->reapStaleTasks($output, $options->staleMinutes);
            return time();
        }
        return $lastReapAt;
    }

    /**
     * Handle the tail of each loop iteration: sleep in daemon mode, signal
     * break in --once mode. Returns true when the caller should exit the loop.
     */
    private function endOfIteration(bool $isOnce, int $sleep): bool
    {
        if ($isOnce) {
            return true;
        }
        usleep($sleep);
        return false;
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
}
