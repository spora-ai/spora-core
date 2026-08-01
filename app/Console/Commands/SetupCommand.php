<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Auth\AuthService;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\DatabaseSeeder;
use Spora\Services\EmailTemplateLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'spora:setup',
    description: 'Run migrations and seed (or reconcile) the database. Idempotent.',
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

            // The seeder is fully idempotent (mail templates use firstOrCreate, the
            // admin upserts roles_mask + status, the agent is applied only if
            // missing) so we run it on every boot. This is the repair path for
            // installs that pre-date a fix to the seeded admin — e.g. a persistent
            // volume where the admin was persisted with verified=0 by an older
            // spora-core release. Skipping the seeder on existing installs would
            // hide those bad rows from the admin login flow.
            $output->writeln('<info>Running database seeder...</info>');
            (new DatabaseSeeder($this->authService, $this->templateLoader, $this->templateImporter))->run();

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Setup failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
