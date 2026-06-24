<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\Core\Extension\Exceptions\PluginInstallFailedException;
use Spora\Core\Extension\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'spora:plugin:update',
    description: 'Update a Spora plugin (or all plugins) via composer update.',
)]
final class PluginUpdateCommand extends Command
{
    public function __construct(
        private readonly PluginManager $plugins,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'package',
            InputArgument::OPTIONAL,
            'Composer package name (vendor/name). Omit to update every installed plugin.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $package = $input->getArgument('package');

        try {
            $result = $this->plugins->update($package !== null ? (string) $package : null);
        } catch (PluginInstallFailedException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (Throwable $e) {
            $io->error('Update failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $label = $result->package !== '' ? $result->package : 'all plugins';
        $io->success("Updated {$label}");

        return Command::SUCCESS;
    }
}
