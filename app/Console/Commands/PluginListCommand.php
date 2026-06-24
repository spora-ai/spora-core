<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\Core\Extension\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'spora:plugin:list',
    description: 'List Spora plugins installed via Composer.',
)]
final class PluginListCommand extends Command
{
    public function __construct(
        private readonly PluginManager $plugins,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $pluginEntries = $this->plugins->list();

        if ($pluginEntries === []) {
            $io->writeln('No Spora plugins installed.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($pluginEntries as $entry) {
            $rows[] = [
                $entry['name'],
                $entry['version'] ?? '(unknown)',
                $entry['path']    ?? '',
            ];
        }

        $io->table(['Package', 'Version', 'Path'], $rows);

        return Command::SUCCESS;
    }
}
