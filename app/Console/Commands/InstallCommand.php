<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\Console\Exceptions\FrontendAssetsMissingException;
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
        try {
            // Pre-migration check — installer routes spora-ai/spora-frontend
            // to public/dist/, so a broken install (404, wrong tag, partial
            // download) leaves the file missing. Fail loudly here rather than
            // letting the operator chase an empty DB or a 200-with-no-UI.
            $frontendIndex = BASE_PATH . '/public/dist/index.html';
            if (! is_file($frontendIndex)) {
                throw new FrontendAssetsMissingException(
                    'public/dist/index.html is missing.' . PHP_EOL
                    . 'Run: composer install spora-ai/spora-frontend'
                );
            }

            $output->writeln('<info>Running Spora database migrations...</info>');

            $this->database->bootDatabaseConnectionOnly();
            $this->installer->install();

            $output->writeln('<info>Done. Schema is up to date.</info>');

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
