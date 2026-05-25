<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\Auth\AuthService;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\DatabaseSeeder;
use Spora\Models\Agent;
use Spora\Models\User;
use Spora\Services\EmailTemplateLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'spora:setup',
    description: 'Run migrations and seed a fresh database, or skip seeding on existing installs.',
)]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly Database $database,
        private readonly DatabaseSchemaInstaller $installer,
        private readonly AuthService $authService,
        private readonly EmailTemplateLoader $templateLoader,
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

            // Only seed on fresh installation (no users, no agents)
            $userCount = User::count();
            $agentCount = Agent::count();

            if ($userCount === 0 && $agentCount === 0) {
                $output->writeln('<info>Fresh installation — running seeder...</info>');
                $seeder = new DatabaseSeeder($this->authService, $this->templateLoader);
                $seeder->run();
            } else {
                $output->writeln('<info>Existing installation detected. Skipping seeding.</info>');
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Setup failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
