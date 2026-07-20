<?php

declare(strict_types=1);

namespace Spora\Build;

use Spora\Console\Commands\OpenApiGenerateCommand as Generate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/spora-build openapi:check` — exits non-zero when the freshly regenerated
 * spec differs from a reference file on disk. CI uses this to gate deploys that
 * would ship stale route documentation.
 *
 * The actual comparison lives in {@see Generate::checkAgainstFile()};
 * this class is the Symfony Console wrapper around it.
 */
#[AsCommand(
    name: 'openapi:check',
    description: 'Exit non-zero if a reference spec differs from a fresh regeneration.',
)]
final class OpenApiCheckCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'reference',
            InputArgument::OPTIONAL,
            'Path to a reference spec to compare against.',
            'openapi.json',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = Generate::checkAgainstFile((string) $input->getArgument('reference'));
        if ($result === Command::SUCCESS) {
            $io->success('Done.');
        } else {
            $io->error('Done (with drift — see error above).');
        }
        return $result;
    }
}
