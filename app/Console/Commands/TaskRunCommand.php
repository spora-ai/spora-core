<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Container\ContainerInterface;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Core\Database;
use Spora\Drivers\DriverFactory;
use Spora\Models\Task;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\Text\Utf8Sanitizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Spawned via proc_open() by WorkerRunCommand when running in --daemon --workers mode.
 * Each invocation is a fresh PHP interpreter — no shared static state.
 *
 * Usage: php bin/spora task:run {taskId}
 *
 * Exit codes:
 *   - 0 (`Command::SUCCESS`)        : task reached `COMPLETED`.
 *   - 1 (`Command::FAILURE`)        : driver / orchestrator exception,
 *                                     task forced to `FAILED`, or the
 *                                     task did not reach `COMPLETED` (e.g.
 *                                     left in `PENDING_APPROVAL`).
 *   - 2 (`TASK_RUN_COMMAND_ABORTED_EXIT`) : task reached `ABORTED`
 *                                     because the user clicked the abort
 *                                     affordance while the worker was
 *                                     mid-tick. Distinct from FAILURE —
 *                                     ABORTED is quiescent (resumable),
 *                                     FAILED is terminal.
 */
final class TaskRunCommand extends Command
{
    /** Worker exit code when the task ended in `ABORTED`. */
    public const TASK_RUN_COMMAND_ABORTED_EXIT = 2;

    public function __construct(
        private readonly Database               $database,
        private readonly ContainerInterface     $container,
        private readonly MercurePublisherInterface $mercure,
    ) {
        parent::__construct('task:run');
    }

    protected function configure(): void
    {
        $this->setDescription('Process a single task (spawned by the worker daemon).');
        $this->addArgument('taskId', InputArgument::REQUIRED, 'The ID of the task to process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = (int) $input->getArgument('taskId');

        $this->database->bootDatabaseConnectionOnly();

        // Graceful SIGTERM / SIGINT handling — exit cleanly without DB corruption.
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn() => exit(0));
            pcntl_signal(SIGINT, static fn() => exit(0));
        }

        // No separate log file — stdout/stderr go to parent's inherited file descriptors
        // so the process manager (systemd/supervisord) captures all child output centrally.
        $orchestrator = $this->buildOrchestrator();

        // Claim the task (QUEUED → RUNNING) inside a lock-safe transaction.
        $task = Capsule::connection()->transaction(function () use ($taskId): ?Task {
            /** @var Task|null $task */
            $task = Task::where('id', $taskId)
                ->whereIn('status', ['QUEUED', 'RUNNING'])
                ->lockForUpdate()
                ->first();

            if ($task === null) {
                return null;
            }

            if ($task->status === 'QUEUED') {
                $task->status = 'RUNNING';
                $task->save();
            }

            return $task;
        });

        if ($task === null) {
            $output->writeln(sprintf('<error>Task %d not found or already claimed.</error>', $taskId));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Processing task %d...</info>', $taskId));

        // Notification is sent by Orchestrator.tick() — do not duplicate here.
        try {
            while (in_array($task->status, ['RUNNING', 'PENDING_APPROVAL'], true)) {
                $orchestrator->tick($task->id);
                $task->refresh();
                if ($task->status === 'ABORTED') {
                    break;
                }
            }
        } catch (Throwable $e) {
            $task->refresh();
            if ($task->status !== 'FAILED') {
                $task->status = 'FAILED';
                $task->failure_reason = Utf8Sanitizer::scrubString($e->getMessage());
                $task->save();
            }
            // Notification is sent by Orchestrator.tick() catch block — do not duplicate here.
            $output->writeln(sprintf(
                '<error>Task %d failed with: %s</error>',
                $task->id,
                $e->getMessage(),
            ));
            return Command::FAILURE;
        }

        $finalStatus = $task->status;
        $output->writeln(sprintf(
            '<info>Task %d finished with status: %s</info>',
            $task->id,
            $finalStatus,
        ));

        return match ($finalStatus) {
            'COMPLETED' => Command::SUCCESS,
            'ABORTED'   => self::TASK_RUN_COMMAND_ABORTED_EXIT,
            default     => Command::FAILURE,
        };
    }

    private function buildOrchestrator(): OrchestratorInterface
    {
        return new Orchestrator(
            $this->container->get(DriverFactory::class),
            new OrchestratorConfig(
                llmConfigService: $this->container->get(LLMConfigService::class),
                toolInstances: $this->container->get('tool_instances'),
                logger: $this->container->get(\Psr\Log\LoggerInterface::class),
                workerMode: WorkerMode::Sync,
                notificationService: $this->container->get(NotificationService::class),
                mercure: $this->mercure,
                toolConfigService: $this->container->get(\Spora\Services\ToolConfigService::class),
                agentService: $this->container->get(\Spora\Services\AgentServiceInterface::class),
                // Wire SubAgentService so the worker-mode batch-boundary hook
                // (executeApprovedPendingToolsForTask → maybeResumeParentFromBatchBoundary)
                // and the per-child hook (completeResponse → maybeResumeParentForChild)
                // can wake up a parent in AWAITING_SUB_AGENTS once every spawned
                // sub-agent has reached a terminal state. Without this wiring
                // both hooks short-circuit on the null guard and the parent
                // stays AWAITING_SUB_AGENTS forever.
                subAgent: $this->container->get(SubAgentServiceInterface::class),
            ),
        );
    }
}
