<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use DirectoryIterator;
use Spora\Core\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sweeps orphaned files from `<storage>/assets/`.
 *
 * Token rotation already prevents stale files from being served (the daily
 * HMAC stops validating), so this command is purely a disk-space hygiene
 * tool. Run it manually after large jobs or on a cron schedule; the
 * framework does not schedule it for you.
 *
 * Filenames look like `<32-hex-hmac>.<16-hex-random>.<ext>`. The first 32
 * hex chars embed a day stamp, so files older than `--max-age-days` are
 * unlinks.
 */
#[AsCommand(
    name: 'assets:gc',
    description: 'Delete asset files whose embedded day-stamp is older than the configured max age.',
)]
final class AssetGcCommand extends Command
{
    public function __construct(
        private readonly Paths $paths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'max-age-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete assets whose day-stamp is older than this many days.',
                '7',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print what would be deleted without actually unlinking files.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $maxAgeDays = (int) $input->getOption('max-age-days');
        if ($maxAgeDays < 0) {
            $io->error('--max-age-days must be >= 0');
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $dir = $this->paths->storage('assets');
        if (! is_dir($dir)) {
            $io->writeln(sprintf('<info>No asset directory at %s — nothing to do.</info>', $dir));
            return Command::SUCCESS;
        }

        $deleted = 0;
        $kept    = 0;
        $errors  = 0;
        $now     = time();

        $iter = new DirectoryIterator($dir);
        foreach ($iter as $file) {
            if ($file->isDir() || $file->isDot()) {
                continue;
            }
            $name = $file->getFilename();
            // Token = `<32 hex hmac>.<16 hex random>`. Re-derive the day
            // stamp from the file's mtime — the filesystem already knows
            // when the file was written, no need to crack the HMAC.
            $ageDays = (int) floor(($now - $file->getMTime()) / 86400);

            if ($ageDays < $maxAgeDays) {
                $kept++;
                continue;
            }
            if ($dryRun) {
                $io->writeln(sprintf('would delete %s (age %d days)', $name, $ageDays));
                $deleted++;
                continue;
            }
            if (! @unlink($file->getPathname())) {
                $io->warning(sprintf('Failed to delete %s', $name));
                $errors++;
                continue;
            }
            $deleted++;
        }

        $io->success(sprintf(
            '%s%d deleted, %d kept, %d errors (max-age=%d days, dry-run=%s)',
            $dryRun ? '[dry-run] ' : '',
            $deleted,
            $kept,
            $errors,
            $maxAgeDays,
            $dryRun ? 'yes' : 'no',
        ));

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
