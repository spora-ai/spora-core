<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Closure;
use Spora\Auth\AuthService;
use Spora\Core\Database;
use Spora\Core\DatabaseSeeder;
use Spora\Services\EmailTemplateLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class SeedCommand extends Command
{
    /**
     * We accept a factory closure instead of AuthService directly,
     * to avoid PHP-DI eagerly constructing Delight\Auth\Auth (which requires
     * a live DB connection) before we have had a chance to call bootDatabaseConnectionOnly().
     *
     * @param Closure(): AuthService $authServiceFactory
     */
    public function __construct(
        private readonly Database $database,
        private readonly Closure $authServiceFactory,
        private readonly EmailTemplateLoader $templateLoader,
    ) {
        parent::__construct('db:seed');
    }

    protected function configure(): void
    {
        $this->setDescription('Seed the database with an initial Admin user and Base Agent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Starting database seeder...</info>');

        try {
            // Boot the DB connection before constructing anything that touches Eloquent or Auth.
            $this->database->bootDatabaseConnectionOnly();

            /** @var AuthService $authService */
            $authService = ($this->authServiceFactory)();
            $seeder = new DatabaseSeeder($authService, $this->templateLoader);
            $seeder->run();

            $output->writeln('<info>Seeding finished successfully.</info>');
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Seeding failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
