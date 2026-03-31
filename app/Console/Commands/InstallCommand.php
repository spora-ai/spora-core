<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'spora:install',
    description: 'Run database migrations (use after deploy, Docker/CI, or on shared hosts).',
)]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly Database $database,
        private readonly DatabaseSchemaInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Running Spora database migrations...</info>');

        try {
            $this->database->bootDatabaseConnectionOnly();
            $this->installer->install();

            $output->writeln('<info>Done. Schema is up to date.</info>');
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Migration failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
