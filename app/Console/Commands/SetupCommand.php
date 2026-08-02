<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\AgentTemplates\AgentTemplateImporter;
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
    description: 'Run migrations and seed a fresh database. Existing installs are skipped — use `db:seed` or `db:repair-admin` for repairs.',
)]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly Database $database,
        private readonly DatabaseSchemaInstaller $installer,
        private readonly AuthService $authService,
        private readonly EmailTemplateLoader $templateLoader,
        private readonly AgentTemplateImporter $templateImporter,
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

            // Seed only on a fresh install. Re-running the seeder on every boot
            // would re-create a deleted or renamed bootstrap admin, which is a
            // backdoor. Repairs go through `bin/spora db:repair-admin`.
            $userCount  = User::count();
            $agentCount = Agent::count();

            if ($userCount === 0 && $agentCount === 0) {
                $output->writeln('<info>Fresh installation — running seeder...</info>');
                (new DatabaseSeeder($this->authService, $this->templateLoader, $this->templateImporter))->run();
            } else {
                $output->writeln('<info>Existing installation detected. Skipping seeding.</info>');
                $output->writeln('<comment>Run `php bin/spora db:repair-admin` if the seeded admin needs promoting (verified=1, Role::ADMIN).</comment>');
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Setup failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
