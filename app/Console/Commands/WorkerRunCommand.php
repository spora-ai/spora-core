<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Task;
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

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
    ) {
        parent::__construct('worker:run');
    }

    protected function configure(): void
    {
        $this->setDescription('Drain QUEUED tasks, or run as a persistent daemon.');
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run as a persistent daemon rather than exiting when empty');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max tasks to process per run (0 = unlimited)', '0');
        $this->addOption('sleep', 's', InputOption::VALUE_REQUIRED, 'Microseconds to sleep when the queue is empty', '500000');
        $this->addOption('stale-minutes', null, InputOption::VALUE_REQUIRED, 'Minutes after which a RUNNING task is considered orphaned and failed', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDaemon     = (bool) $input->getOption('daemon');
        $limit        = (int) $input->getOption('limit');
        $sleep        = (int) $input->getOption('sleep');
        $staleMinutes = (int) $input->getOption('stale-minutes');

        if ($isDaemon && extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->shouldQuit = true;
            });
            pcntl_signal(SIGINT, function (): void {
                $this->shouldQuit = true;
            });
        }

        $processed  = 0;
        $lastReapAt = 0;

        if ($isDaemon) {
            $output->writeln('<info>Starting daemon worker. Press Ctrl+C to exit.</info>');
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
                $output->writeln(sprintf('<error>Database error: %s</error>', $e->getMessage()));
                if ($isDaemon) {
                    usleep($sleep * 5); // back off on DB errors
                    continue;
                } else {
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

            $output->writeln(sprintf('<info>Processing task %d...</info>', $task->id));

            try {
                $this->orchestrator->tick($task->id);
            } catch (Throwable $e) {
                // tick() already marked the task FAILED and re-threw — log and move on.
                $output->writeln(sprintf('<error>Task %d failed: %s</error>', $task->id, $e->getMessage()));
            }

            // Count all attempts toward the limit so --limit is a reliable cap on tasks touched,
            // not just tasks that succeeded. Failed tasks are already transitioned out of RUNNING.
            $processed++;

            // In daemon mode, memory limits can be breached after hours of processing.
            gc_collect_cycles();
        }

        if ($isDaemon && $this->shouldQuit) {
            $output->writeln('<info>Daemon shut down gracefully.</info>');
        } else {
            $output->writeln(sprintf('<info>Worker run complete. Processed %d task(s).</info>', $processed));
        }

        return Command::SUCCESS;
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
            $output->writeln(sprintf(
                '<comment>Reaped %d orphaned RUNNING task(s) (idle > %d min).</comment>',
                $reaped,
                $staleMinutes,
            ));
        }
    }
}
