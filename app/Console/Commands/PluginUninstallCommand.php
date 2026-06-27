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
    name: 'plugin:uninstall',
    description: 'Uninstall a Spora plugin via composer remove.',
)]
final class PluginUninstallCommand extends Command
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
            InputArgument::REQUIRED,
            'Composer package name (vendor/name) to uninstall.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $package = (string) $input->getArgument('package');

        try {
            $this->plugins->uninstall($package);
        } catch (PluginInstallFailedException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (Throwable $e) {
            $io->error('Uninstall failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Uninstalled {$package}");
        return Command::SUCCESS;
    }
}
