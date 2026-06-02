<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Container\ContainerInterface;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Core\Database;
use Spora\Drivers\DriverFactory;
use Spora\Models\Task;
use Spora\Services\LLMConfigService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
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
 */
final class TaskRunCommand extends Command
{
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
        $orchestrator = $this->buildOrchestrator($output);

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
            }
        } catch (Throwable $e) {
            $task->refresh();
            if ($task->status !== 'FAILED') {
                $task->status = 'FAILED';
                $task->failure_reason = $e->getMessage();
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

        return $finalStatus === 'COMPLETED' ? Command::SUCCESS : Command::FAILURE;
    }

    private function buildOrchestrator(OutputInterface $output): OrchestratorInterface
    {
        $config = $this->container->get('config');
        return new Orchestrator(
            driverFactory: $this->container->get(DriverFactory::class),
            llmConfigService: $this->container->get(LLMConfigService::class),
            toolInstances: $this->container->get('tool_instances'),
            logger: $this->container->get(\Psr\Log\LoggerInterface::class),
            workerMode: WorkerMode::Sync,
            notificationService: $this->container->get(NotificationService::class),
            mercure: $this->mercure,
            toolConfigService: $this->container->get(\Spora\Services\ToolConfigService::class),
        );
    }
}
