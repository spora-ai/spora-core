<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\Core\Extension\PluginInstallRequest;
use Spora\Core\Extension\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'spora:plugin:install',
    description: 'Install a Spora plugin from Packagist or a local path repository.',
)]
final class PluginInstallCommand extends Command
{
    public function __construct(
        private readonly PluginManager $plugins,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::REQUIRED, 'Composer package name (vendor/name).')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Optional version constraint, e.g. ^1.0.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Absolute path to a local plugin checkout to install as a Composer path repository.')
            ->setHelp(<<<HELP
Installs <info>vendor/name</info> via <comment>composer require</comment>. Pass
<info>--version=^1.0</info> to pin a constraint, or <info>--path=/abs/path</info>
to install a local checkout as a path repository (useful for plugin
development against a sibling git clone).

<comment>--version</comment> and <comment>--path</comment> are mutually exclusive.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $request = $this->buildRequest($input);
        if ($request === null) {
            $io->error('Pass either --version or --path, not both.');
            return Command::FAILURE;
        }

        return $this->runInstall($io, $request);
    }

    /**
     * Build a {@see PluginInstallRequest} from the parsed input, or null if the
     * input is internally inconsistent (--version and --path are mutually
     * exclusive). Returned in its own helper so {@see execute()} stays at 2
     * returns — see SONAR rule brain-overload.
     */
    private function buildRequest(InputInterface $input): ?PluginInstallRequest
    {
        $package = (string) $input->getArgument('package');
        $version = $input->getOption('version');
        $path    = $input->getOption('path');

        if ($version !== null && $path !== null) {
            return null;
        }

        return new PluginInstallRequest(
            package: $package,
            version: $version !== null ? (string) $version : null,
            path: $path    !== null ? (string) $path : null,
        );
    }

    /**
     * Run the install and surface the outcome via the given SymfonyStyle.
     * Returns 2 paths only (FAILURE / SUCCESS) so it stays under SONAR's
     * 3-return cap per method. Catches every Throwable — both
     * PluginInstallFailedException and any unexpected error — because both
     * surface as a one-line stderr error to the operator.
     */
    private function runInstall(SymfonyStyle $io, PluginInstallRequest $req): int
    {
        try {
            $result = $this->plugins->install($req);
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Installed {$result->package}");
        if ($result->message !== '') {
            $io->writeln($result->message);
        }
        return Command::SUCCESS;
    }
}
