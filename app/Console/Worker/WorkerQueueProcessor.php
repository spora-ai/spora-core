<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Core\Paths;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Owns the queue/retry lifecycle: claims QUEUED tasks (sync or via spawned
 * children), processes retry tasks whose `retry_after` has elapsed, and
 * reaps finished child processes. Extracted from WorkerRunCommand to keep
 * that class under S1448's method limit.
 */
final class WorkerQueueProcessor
{
    private const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const SHUTDOWN_GRACE_MICROS = 30_000_000;

    /**
     * Per-pipe read cap inside {@see reapChildren()}. Multiplied by two
     * (stdout + stderr) this is the worst-case working-set growth per child
     * per sweep before {@see truncateExcerpt()} applies the truncation marker
     * and {@see proc_close()} releases the OS pipe.
     */
    private const CHILD_READ_CHUNK = 65_536;

    /**
     * Per-pipe excerpt budget for the `child_exit` log line. Mirrors
     * {@see \Spora\Core\Kernel::mapPluginInstallFailureToResponse()}'s
     * 8 KiB composer-stderr cap so a runaway child can't blow up the
     * Monolog buffer; 64 KiB is bigger because tasks can emit genuinely
     * useful output beyond a one-line error.
     */
    private const CHILD_EXCERPT_BUDGET = 65_536;

    /**
     * @var array<int, array{proc: resource, pipes: array<int, resource>, task_id: int}>
     */
    private array $childProcs = [];

    /** @var array<int, array{stdout: string, stderr: string}> */
    private array $childStreams = [];

    /** @var Closure(int): list<string> */
    private readonly Closure $cmdFactory;

    /**
     * @param Closure(int): list<string>|null $cmdFactory  Tests inject a tiny command to assert exit codes and budgets without booting a full Spora child task.
     */
    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherInterface $mercure,
        private readonly NotificationService $notificationService,
        private readonly Paths $paths,
        ?Closure $cmdFactory = null,
    ) {
        $this->cmdFactory = $cmdFactory ?? function (int $taskId): array {
            return [
                PHP_BINARY,
                $this->paths->base('bin/spora'),
                'task:run',
                (string) $taskId,
            ];
        };
    }

    /**
     * Claim and process a single QUEUED task synchronously in the parent process.
     */
    public function processQueuedTaskSync(OutputInterface $output, int $sleep, int &$processed): void
    {
        try {
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

        $this->mercure->publishForPrincipal($task->id, $task->principalOwnerId(), [
            'task_id' => $task->id,
            'status'  => 'RUNNING',
        ]);

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

            Task::where('id', $task->id)
                ->where('status', 'RUNNING')
                ->update([
                    'status'         => 'FAILED',
                    'failure_reason' => $e->getMessage(),
                    'error_code'     => 'UNKNOWN',
                    'error_message' => $e->getMessage(),
                ]);
        }

        $processed++;
    }

    /**
     * Spawn child processes for all available QUEUED tasks.
     * Loops until either maxWorkers (0 = unlimited) is reached or no more QUEUED tasks exist.
     */
    public function processQueuedTaskWithChild(OutputInterface $output, int $maxWorkers, int &$processed): void
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

            $this->mercure->publishForPrincipal($task->id, $task->principalOwnerId(), [
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
     *
     * Uses explicit stdin/stdout/stderr pipes so {@see reapChildren()} can
     * attach the exit code to a bounded excerpt of the child's output.
     * The earlier empty-`$descriptorspec` form left children inheriting the
     * parent's stdout/stderr — interleaved across concurrent children and
     * invisible to the reaper, so an OOM-killed `task:run` left no trace
     * except a silent prompt hang.
     */
    public function spawnChild(int $taskId): ?int
    {
        $cmd = ($this->cmdFactory)($taskId);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->logger->error('Failed to spawn child process', [
                'task_id' => $taskId,
                'cmd'     => $cmd,
            ]);
            return null;
        }

        // Parent doesn't write to stdin.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($proc);
        $pid = (int) $status['pid'];

        $this->childProcs[$pid] = [
            'proc'    => $proc,
            'pipes'   => $pipes,
            'task_id' => $taskId,
        ];
        // Slot the accumulator on the same pid key as $childProcs — pipe
        // resource IDs get recycled by PHP after fclose, so a fresh child
        // could otherwise inherit a still-warm slot from a previous one.
        $this->childStreams[$pid] = ['stdout' => '', 'stderr' => ''];

        return $pid;
    }

    /**
     * Reap any child processes that have exited, draining their pipes,
     * emitting a {@code child_exit} log line per finished child, and echoing
     * a one-line "Task X finished with status: Y" status to {@code $output}
     * so operators running {@code bin/spora worker:run} in a TTY see one
     * coherent block per task without child output interleaving across
     * concurrent workers.
     */
    public function reapChildren(OutputInterface $output): void
    {
        foreach ($this->childProcs as $pid => $entry) {
            $this->reapOneChild($pid, $entry, $output);
        }
    }

    /**
     * Drain the pipes for a single child, decide whether it has exited, and
     * if so emit the {@code child_exit} log line and release its handles.
     *
     * @param int $pid
     * @param array{proc: resource, pipes: array<int, resource>, task_id: int} $child
     */
    private function reapOneChild(int $pid, array $child, OutputInterface $output): void
    {
        $proc       = $child['proc'];
        $pipes      = $child['pipes'];
        $taskId     = $child['task_id'];
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;

        // Drain whatever bytes are ready on this sweep. Non-blocking
        // reads return whatever's currently in the kernel pipe buffer.
        // We must call stream_get_contents() — even on an empty pipe
        // — before feof() will flip true after the child closes its
        // write end. Reading nothing is enough to advance the file
        // position past the EOF marker once the child has exited.
        $drainedStdout = $this->drainPipe($stdoutPipe);
        $drainedStderr = $this->drainPipe($stderrPipe);

        $this->childStreams[$pid]['stdout'] .= $drainedStdout;
        $this->childStreams[$pid]['stderr'] .= $drainedStderr;

        // Bound the per-pipe accumulator. A runaway child (worse than the
        // 200 KB test burst) would otherwise grow our working set
        // unbounded across reap sweeps.
        $this->childStreams[$pid]['stdout'] = $this->capAccumulator($this->childStreams[$pid]['stdout']);
        $this->childStreams[$pid]['stderr'] = $this->capAccumulator($this->childStreams[$pid]['stderr']);

        // Done gate: feof() on stdout is the reliable signal that
        // (a) the child has closed its write end (i.e. exited) AND
        // (b) we've consumed everything it wrote. PHP's
        // `proc_get_status` reports `running === true` until the parent
        // fully drains the pipes AND calls proc_close, so it isn't
        // sufficient on its own.
        if (!is_resource($stdoutPipe) || !feof($stdoutPipe)) {
            return;
        }

        $stdout   = $this->childStreams[$pid]['stdout'];
        $stderr   = $this->childStreams[$pid]['stderr'];
        $status   = proc_get_status($proc);
        $exitCode = $status['exitcode'];
        $signal   = (int) $status['termsig'];

        $stdoutExcerpt = $this->truncateExcerpt($stdout);
        $stderrExcerpt = $this->truncateExcerpt($stderr);

        $level = $exitCode === 0 ? 'info' : 'error';
        $this->logger->{$level}('child_exit', [
            'task_id'        => $taskId,
            'pid'            => $pid,
            'exit_code'      => $exitCode,
            'signal'         => $signal,
            'stdout_excerpt' => $stdoutExcerpt,
            'stderr_excerpt' => $stderrExcerpt,
        ]);

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($proc);

        // Surface one coherent status line per child to the operator's
        // terminal — replaces the "Task X finished with status: Y" line
        // that used to come through stdout inheritance before Track 3
        // routed the child's stdio into bounded pipes. The status is
        // read from the DB after the child has run, not from the child's
        // exit code, because Spora tasks can complete successfully (exit
        // 0) yet land on FAILED via Orchestrator's exception handler, and
        // vice versa.
        $output->writeln(sprintf(
            '<info>Task %d finished with status: %s</info>',
            $taskId,
            $this->resolveFinalStatus($taskId),
        ));

        unset(
            $this->childProcs[$pid],
            $this->childStreams[$pid],
        );
    }

    /**
     * Read the task's final DB status after the child has exited.
     * Returns 'UNKNOWN' if the row is missing (deleted mid-run) or the
     * DB read throws — neither is fatal; the operator still sees a
     * "finished" line so they know the child exited.
     */
    private function resolveFinalStatus(int $taskId): string
    {
        try {
            $task = Task::find($taskId);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to read task status after child exit', [
                'task_id'        => $taskId,
                'exception_class' => get_class($e),
                'message'        => $e->getMessage(),
            ]);
            return 'UNKNOWN';
        }
        return $task === null ? 'UNKNOWN' : $task->status;
    }

    /**
     * Cap an in-progress accumulator at {@see CHILD_EXCERPT_BUDGET} bytes once
     * it has grown to 4× that threshold, appending a marker so an operator
     * reading the {@code child_exit} log line knows the bytes were dropped
     * (rather than truncated mid-message by an invisible cap).
     */
    private function capAccumulator(string $bytes): string
    {
        if (strlen($bytes) <= self::CHILD_EXCERPT_BUDGET * 4) {
            return $bytes;
        }
        return substr($bytes, 0, self::CHILD_EXCERPT_BUDGET)
            . '[...truncated; reaper safety ceiling reached...]';
    }

    /**
     * Process in-place retries whose `retry_after` has elapsed.
     *
     * The retry chain is now a single failed task whose `retry_of_task_id`
     * points to itself; the worker hands it back to Orchestrator::retry()
     * which resets error fields, re-ticks, and (on success) flips status to
     * COMPLETED. Cancelling the chain is done via cancelRetryChain, which
     * clears `retry_after` so this query stops matching.
     */
    public function processRetryQueue(): void
    {
        $now = date(self::DB_DATETIME_FORMAT);

        $retryTasks = Capsule::connection()->select("
            SELECT id, retry_count, agent_id
            FROM tasks
            WHERE status = 'FAILED'
              AND retry_after IS NOT NULL
              AND retry_after <= ?
              AND retry_of_task_id IS NOT NULL
        ", [$now]);

        if ($retryTasks === []) {
            return;
        }

        $taskIds = array_column($retryTasks, 'id');

        $allRetryTasks = Task::findMany($taskIds);
        $agentMaxRetries = $this->resolveAgentMaxRetries($retryTasks);

        foreach ($allRetryTasks as $retryTask) {
            $retryCount = (int) $retryTask->retry_count;
            $maxRetries = $agentMaxRetries[$retryTask->agent_id] ?? 0;

            $this->notificationService->notifyTaskRetrying($retryTask, $retryCount, $maxRetries);

            $this->orchestrator->retry((int) $retryTask->id);

            $this->mercure->publishForPrincipal($retryTask->id, $retryTask->principalOwnerId(), [
                'task_id' => $retryTask->id,
                'status'  => 'RUNNING',
            ]);
        }
    }

    /**
     * Gracefully shut down the parent and all child processes.
     */
    public function shutdownParent(): void
    {
        foreach ($this->childProcs as $entry) {
            proc_terminate($entry['proc']);
        }

        $start = hrtime(true);
        while (count($this->childProcs) > 0 && (hrtime(true) - $start) < self::SHUTDOWN_GRACE_MICROS) {
            $this->reapChildren(new NullOutput());
            if (count($this->childProcs) > 0) {
                usleep(100_000);
            }
        }

        foreach ($this->childProcs as $entry) {
            proc_close($entry['proc']);
        }
        $this->childProcs = [];
    }

    /**
     * @param list<object> $retryTasks
     * @return array<int, int>
     */
    private function resolveAgentMaxRetries(array $retryTasks): array
    {
        $agentIds = array_unique(array_column($retryTasks, 'agent_id'));
        $allAgents = Agent::findMany($agentIds);
        $agentMaxRetries = [];
        foreach ($allAgents as $a) {
            $agentMaxRetries[$a->id] = $a->max_retries;
        }
        return $agentMaxRetries;
    }

    /**
     * Drain up to {@code $maxBytes} bytes from a non-blocking pipe. Returns
     * whatever's currently in the kernel pipe buffer — empty for null,
     * non-resource, or closed handles (the "child already closed its end"
     * case after `proc_close()`). EOF detection itself lives in the caller;
     * {@code feof()} is the reliable done-gate, not the byte count.
     */
    private function drainPipe(mixed $pipe, int $maxBytes = self::CHILD_READ_CHUNK): string
    {
        if (!is_resource($pipe)) {
            return '';
        }
        $bytes = stream_get_contents($pipe, $maxBytes);
        if ($bytes === false) {
            return '';
        }
        return $bytes;
    }

    /**
     * Truncate already-drained pipe bytes to the {@code child_exit} budget.
     * Separated from {@see drainPipe()} so {@code reapOneChild()} can also
     * truncate the post-EOF append safely.
     *
     * Returning the input unchanged when under budget keeps the round-trip
     * cost O(1) for the happy path where the child produced no output.
     */
    private function truncateExcerpt(string $bytes): string
    {
        $marker = '[...truncated...]';
        $budget = self::CHILD_EXCERPT_BUDGET;
        if (strlen($bytes) <= $budget - strlen($marker)) {
            return $bytes;
        }
        return substr($bytes, 0, $budget - strlen($marker)) . $marker;
    }
}
