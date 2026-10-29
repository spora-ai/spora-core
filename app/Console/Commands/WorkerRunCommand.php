<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\Console\Concerns\AppliesSettingsOverlay;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Console\Worker\WorkerQueueProcessor;
use Spora\Console\Worker\WorkerReaper;
use Spora\Console\WorkerLoopOptions;
use Spora\Core\Config;
use Spora\Core\Database;
use Spora\Core\Paths;
use Spora\Scheduling\DueWorkDispatcher;
use Spora\Services\AgentApi\AgentApiCallbackDispatcher;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\PrincipalResolver;
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
 *
 * Delegates the actual work to:
 *   - WorkerReaper:          orphan task reaper
 *   - WorkerQueueProcessor:  queue/retry/child-process lifecycle
 *   - ScheduledRunProcessor: scheduled-run claim, dispatch, finalize
 *   - DueWorkDispatcher:     plugin-owned work that is due
 *   - AgentApiCallbackDispatcher: Agent API webhook delivery
 */
final class WorkerRunCommand extends Command
{
    use AppliesSettingsOverlay;

    private const REAP_INTERVAL_SECONDS = 300;

    /**
     * Seconds between refreshes of the principal list the worker acts for.
     *
     * The list is a full scan of the principals table, and the daemon runs
     * the loop twice a second by default — reading it on every iteration
     * would cost two table scans a second for the life of the daemon.
     */
    private const PRINCIPAL_SCOPE_REFRESH_SECONDS = 60;

    private bool $shouldQuit = false;
    private mixed $lockFd = null;

    /** @var list<int>|null */
    private ?array $principalScope = null;

    private int $principalScopeReadAt = 0;

    /** Tracks how many scheduled runs were processed in the last processScheduledRuns() call (testing hook). */
    public int $lastScheduledProcessedCount = 0;

    private readonly WorkerReaper $reaper;
    private readonly WorkerQueueProcessor $queueProcessor;
    private readonly ScheduledRunProcessor $scheduledRunProcessor;

    /** Null when the container cannot build it — a test harness, or a trimmed runtime. */
    private readonly ?AgentApiCallbackDispatcher $callbackDispatcher;

    /** Null when the container cannot build it — a test harness, or a trimmed runtime. */
    private readonly ?DueWorkDispatcher $dueWorkDispatcher;

    public function __construct(
        private readonly Database            $database,
        OrchestratorInterface                $orchestrator,
        private readonly LoggerInterface        $logger,
        private readonly ContainerInterface     $container,
        MercurePublisherInterface            $mercure,
        NotificationService                  $notificationService,
        private readonly Paths                $paths,
        private readonly WorkerRuntimeMode    $workerRuntimeMode,
    ) {
        $this->reaper = $container->get(WorkerReaper::class);
        // Resolved from the container rather than injected, for the same
        // reason the reaper is: the command's constructor is already long and
        // positional. Optional, because a test container that does not know
        // the class must still be able to build this.
        $this->callbackDispatcher = $container->has(AgentApiCallbackDispatcher::class)
            ? $container->get(AgentApiCallbackDispatcher::class)
            : null;
        $this->dueWorkDispatcher = $container->has(DueWorkDispatcher::class)
            ? $container->get(DueWorkDispatcher::class)
            : null;
        $this->queueProcessor = new WorkerQueueProcessor(
            $orchestrator,
            $logger,
            $mercure,
            $notificationService,
            $paths,
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
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run once: process one due scheduled run (and QUEUED tasks with --include-queue), then exit. Recurring backlogs are drained by the next cron fire. Ideal for cron-driven deployments.');
        $this->addOption('include-queue', null, InputOption::VALUE_NONE, 'With --once: also drain the QUEUED task queue in the same run');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max QUEUED tasks to process per run (0 = unlimited)', '0');
        $this->addOption('sleep', 's', InputOption::VALUE_REQUIRED, 'Microseconds to sleep when the queue is empty', '500000');
        $this->addOption('stale-minutes', null, InputOption::VALUE_REQUIRED, 'Minutes before a RUNNING task is orphaned (0 = disabled, omit to use config/default/60)', null);
        $this->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Max concurrent child processes (0 = unlimited, default: unlimited)', null);
        $this->addOption('reap-only', null, InputOption::VALUE_NONE, 'Reap orphaned RUNNING tasks once, then exit. No queue processing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Client mode drives ticks via /api/v1/tasks/{id}/tick and the reaper
        // via /worker/housekeeping — bin/spora worker:run has no work to do.
        // Exit before touching the DB so operators who run it manually see a
        // clear explanation instead of a silent no-op or confusing empty daemon.
        if ($this->workerRuntimeMode === WorkerRuntimeMode::Client) {
            $this->writeClientModeError($output);
            return Command::FAILURE;
        }

        return $this->runWorker($input, $output);
    }

    private function writeClientModeError(OutputInterface $output): void
    {
        $output->writeln('<error>Server-side worker disabled: Spora is running in client-worker mode.</error>');
        $output->writeln('<comment>Tasks are driven by your browser via POST /api/v1/tasks/{id}/tick.</comment>');
        $output->writeln('<comment>See https://docs.spora-ai.com/deploy/shared-host/client-worker-mode</comment>');
    }

    private function runWorker(InputInterface $input, OutputInterface $output): int
    {
        $this->applyRuntimeSettings($this->container, $this->database);

        // Pin UTC before any timestamp comparison. The DB columns are UTC (due_at,
        // next_run_at, last_run_at, completed_at, created_at, updated_at), and the
        // claim path uses gmdate() so it's UTC regardless of this pin — but other
        // date() calls in the worker process (WorkerQueueProcessor's retry_after
        // math, log timestamps) read from the default tz and need to agree with
        // the UTC-stored columns.
        date_default_timezone_set('UTC');

        $isDaemon = (bool) $input->getOption('daemon');
        $isOnce = (bool) $input->getOption('once');
        $includeQueue = (bool) $input->getOption('include-queue');
        $isReapOnly = (bool) $input->getOption('reap-only');

        $isDaemon = $isDaemon || (!$isOnce && !$isReapOnly);

        // Resolve stale-minutes: CLI always wins; omit flag → config → default (60)
        // Explicit 0 from CLI means "disabled" (never reap).
        $staleMinutesRaw = $input->getOption('stale-minutes');
        $staleMinutes = (int) ($staleMinutesRaw !== null
            ? $staleMinutesRaw
            : (int) $this->container->get(Config::class)->getInt('SPORA_WORKER_STALE_MINUTES', 60));

        $limit = (int) $input->getOption('limit');
        $sleep = (int) $input->getOption('sleep');

        // Resolution: CLI --workers N (>0) → cap at N; CLI absent/0 → config → 0 (unlimited)
        $workersCli = $input->getOption('workers');
        $maxWorkers = match (true) {
            $workersCli !== null && $workersCli !== '0' => (int) $workersCli,
            default => (int) $this->container->get(Config::class)->getInt('SPORA_MAX_WORKERS', 0),
        };

        $earlyExit = $this->prepareForRun(
            $output,
            $isDaemon,
            $isOnce,
            $isReapOnly,
            $includeQueue,
            $staleMinutes,
            $limit,
        );
        if ($earlyExit !== null) {
            return $earlyExit;
        }

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

        // A second sweep, after the loop rather than only inside it: in
        // `--once --include-queue -l N` the loop exits as soon as its budget
        // is spent, which can be before the task it just ran synchronously
        // settles. Without this, cron deployments never deliver a callback.
        $this->dispatchAgentApiCallbacks();

        $this->queueProcessor->shutdownParent();
        $this->releaseLock();

        if (!$isDaemon || !$this->shouldQuit) {
            $output->writeln(sprintf('<info>Worker run complete. Processed %d task(s).</info>', $processed));
            $this->logger->info('Worker run complete', ['processed' => $processed]);
        }

        return Command::SUCCESS;
    }

    /**
     * Run the early-exit gates and side-effect setup shared by every mode:
     * mode-flag validation, daemon lock acquisition, signal-handler wiring,
     * the startup banner, and the initial orphan reaping sweep.
     *
     * Returns Command::FAILURE on validation/lock failure, or null once it
     * is safe to proceed with mode-specific work.
     */
    private function prepareForRun(
        OutputInterface $output,
        bool $isDaemon,
        bool $isOnce,
        bool $isReapOnly,
        bool $includeQueue,
        int $staleMinutes,
        int $limit,
    ): ?int {
        $validationError = $this->validateModeFlags($output, $isDaemon, $isOnce, $isReapOnly);
        if ($validationError !== null) {
            return $validationError;
        }

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

        return null;
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
            $this->lastScheduledProcessedCount = $this->scheduledRunProcessor->lastProcessed;
            $this->dispatchDueWork();
            $this->dispatchAgentApiCallbacks();
        }

        $this->queueProcessor->reapChildren($output);

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
     * Run the plugin-owned work that is due.
     *
     * The server worker has no caller, so it acts for the whole install —
     * the same scope its scheduled-run claim has. A plugin that fails must
     * not take the worker with it, so everything here is contained.
     */
    private function dispatchDueWork(): void
    {
        if ($this->dueWorkDispatcher === null) {
            return;
        }

        try {
            $executed = $this->dueWorkDispatcher->dispatch($this->principalScope());

            if ($executed > 0) {
                $this->logger->info('Due work executed', ['executed' => $executed]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Due-work dispatch failed', ['exception' => $e]);
        }
    }

    /**
     * The principals the worker may act for.
     *
     * Refreshed on an interval rather than on every iteration: a principal
     * created while the daemon runs is picked up within the interval, and
     * the daemon does not scan the principals table twice a second.
     *
     * @return list<int>
     */
    private function principalScope(): array
    {
        $now = time();
        if ($this->principalScope === null
            || ($now - $this->principalScopeReadAt) >= self::PRINCIPAL_SCOPE_REFRESH_SECONDS) {
            $this->principalScope = (new PrincipalResolver())->allPrincipalIds();
            $this->principalScopeReadAt = $now;
        }

        return $this->principalScope;
    }

    /**
     * Drain any Agent API callback the last iteration made due. Swallows
     * everything: a receiver that is down must never take the worker with it,
     * and `dispatchDue()` already logs rather than throws.
     */
    private function dispatchAgentApiCallbacks(): void
    {
        if ($this->callbackDispatcher === null) {
            return;
        }

        try {
            $this->callbackDispatcher->dispatchDue();
        } catch (Throwable $e) {
            $this->logger->error('Agent API callback sweep failed', ['error' => $e->getMessage()]);
        }
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
        $lockFile = $this->paths->storage('spora-worker.lock');

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
