<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Agents\OrchestratorInterface;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
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

    /** @var array<int, resource> */
    private array $childProcs = [];

    public function __construct(
        private readonly OrchestratorInterface $orchestrator,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherInterface $mercure,
        private readonly NotificationService $notificationService,
    ) {}

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

        $this->mercure->publish($task->id, $task->user_id, [
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
     */
    public function spawnChild(int $taskId): ?int
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
     * Reap any child processes that have exited.
     */
    public function reapChildren(): void
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
     * Process retry tasks whose retry_after <= now.
     *
     * All retry tasks link to the ROOT original task (not the immediate parent),
     * enabling single-query cancellation: WHERE retry_of_task_id = root AND retry_count >= N.
     */
    public function processRetryQueue(): void
    {
        $now = date(self::DB_DATETIME_FORMAT);

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

        Capsule::table('tasks')
            ->whereIn('id', $taskIds)
            ->update(['status' => 'RUNNING']);

        $allRetryTasks = Task::findMany($taskIds);
        $agentMaxRetries = $this->resolveAgentMaxRetries($retryTasks);

        foreach ($allRetryTasks as $retryTask) {
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
    public function shutdownParent(): void
    {
        foreach ($this->childProcs as $proc) {
            proc_terminate($proc);
        }

        $start = hrtime(true);
        while (count($this->childProcs) > 0 && (hrtime(true) - $start) < self::SHUTDOWN_GRACE_MICROS) {
            $this->reapChildren();
            if (count($this->childProcs) > 0) {
                usleep(100_000);
            }
        }

        foreach ($this->childProcs as $proc) {
            proc_close($proc);
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
}
