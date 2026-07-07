<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared scaffolding for Spora's GC-style console commands.
 *
 * Both {@see MediaArchiveGcCommand} and {@see AssetGcCommand} follow the
 * same operator-facing shape: a `--max-age-days` option (with a sensible
 * default per command), a `--dry-run` flag, an iteration over candidates,
 * counters for `deleted` / `kept` / `errors`, and a one-line success
 * summary at the end. This base class owns the option parsing and the
 * summary emitter; subclasses only implement the iteration body.
 */
abstract class AbstractGcCommand extends Command
{
    /** @return int Default value for the `--max-age-days` option. */
    abstract protected function maxAgeDefault(): int;

    /** @return string Human-readable description of the `--max-age-days` option. */
    abstract protected function maxAgeDescription(): string;

    /** @return string Human-readable description of the `--dry-run` option. */
    abstract protected function dryRunDescription(): string;

    protected function configure(): void
    {
        $this
            ->addOption(
                'max-age-days',
                null,
                InputOption::VALUE_REQUIRED,
                $this->maxAgeDescription(),
                (string) $this->maxAgeDefault(),
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                $this->dryRunDescription(),
            );
    }

    /**
     * Parse + validate the shared `--max-age-days` / `--dry-run` options.
     * Returns `null` (and writes the validation error) when the value
     * is negative — the caller should return {@see Command::FAILURE}.
     *
     * @return array{0: int, 1: bool}|null
     */
    protected function parseGcOptions(SymfonyStyle $io, InputInterface $input): ?array
    {
        $maxAgeDays = (int) $input->getOption('max-age-days');
        if ($maxAgeDays < 0) {
            $io->error('--max-age-days must be >= 0');
            return null;
        }

        return [$maxAgeDays, (bool) $input->getOption('dry-run')];
    }

    /**
     * Emit the standard "N deleted, N kept, N errors" success summary
     * and return the matching {@see Command} exit code. Identical
     * format across every GC command so operators get a consistent UX.
     */
    protected function emitGcSummary(
        SymfonyStyle $io,
        int $deleted,
        int $kept,
        int $errors,
        int $maxAgeDays,
        bool $dryRun,
    ): int {
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
