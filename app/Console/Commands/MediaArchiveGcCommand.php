<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Spora\Core\Paths;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaArchiveService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Sweeps orphaned rows from the media archive.
 *
 * Default mode (no `--temporary`): a row is orphaned when its on-disk
 * asset is gone (GC'd by `assets:gc`, manually deleted, or never
 * promoted because it landed in `storage_mode = external` and the CDN
 * URL 404'd — though the latter is filtered out by the `local`-only
 * filter below). Rows whose `storage_mode != 'local'` are skipped:
 * external rows are allowed to outlive their CDN because the row IS
 * the durable record.
 *
 * `--temporary` mode: ignore storage mode entirely and reap rows whose
 * `is_temporary=TRUE` AND whose `created_at` is older than
 * `--older-than-hours` hours ago (default 24). This is the operator-
 * driven age-based counterpart to the real-time per-agent retention
 * purge that runs on `POST /api/v1/media`. The count-based ceiling
 * already keeps the (user, agent) temp set bounded; this command
 * handles the leftover (user-only) rows that have no agent_id (so
 * the count-based sweep can't see them) plus the long-lived orphans
 * an operator wants to clear out in bulk.
 */
#[AsCommand(
    name: 'media:gc',
    description: 'Delete media_assets rows whose on-disk file is missing, or --temporary rows older than --older-than-hours.',
)]
final class MediaArchiveGcCommand extends AbstractGcCommand
{
    /**
     * Default age window for `--temporary` mode. Picked so a stale
     * voice clip parked for a day is reaped on the next overnight
     * sweep, while a transcript the user just uploaded survives at
     * least one full user session.
     */
    private const TEMP_DEFAULT_OLDER_THAN_HOURS = 24;

    public function __construct(
        private readonly MediaArchiveService $service,
        private readonly Paths $paths,
    ) {
        parent::__construct();
    }

    protected function maxAgeDefault(): int
    {
        return 30;
    }

    protected function maxAgeDescription(): string
    {
        return 'Only consider rows older than this many days (0 = all). Ignored when --temporary is set.';
    }

    protected function dryRunDescription(): string
    {
        return 'Print what would be deleted without actually deleting rows.';
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption(
                'temporary',
                null,
                InputOption::VALUE_NONE,
                'Reap rows where is_temporary=TRUE instead of orphaned on-disk rows. Use with --older-than-hours.',
            )
            ->addOption(
                'older-than-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'When --temporary is set, only reap temp rows older than this many hours. Defaults to 24.',
                (string) self::TEMP_DEFAULT_OLDER_THAN_HOURS,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $temporary = (bool) $input->getOption('temporary');
        $olderThanHours = (int) $input->getOption('older-than-hours');
        if ($temporary && $olderThanHours < 0) {
            $io->error('--older-than-hours must be >= 0');
            return Command::FAILURE;
        }

        $parsed = $this->parseGcOptions($io, $input);
        if ($parsed === null) {
            return Command::FAILURE;
        }
        [$maxAgeDays, $dryRun] = $parsed;

        if ($temporary) {
            return $this->runTempSweep($io, $olderThanHours, $dryRun);
        }

        return $this->runOrphanSweep($io, $maxAgeDays, $dryRun);
    }

    /**
     * Default mode — reaps `local`-mode rows whose on-disk file is
     * missing AND that are at least `$maxAgeDays` old. Same shape as
     * before; lifted into its own helper so the temp sweep can stay
     * focused without inlining the disk-stat branch.
     */
    private function runOrphanSweep(SymfonyStyle $io, int $maxAgeDays, bool $dryRun): int
    {
        $assetsDir = $this->paths->storage('assets');
        $cutoff = \Illuminate\Support\Carbon::now()->subDays($maxAgeDays);

        $query = MediaAsset::query()
            ->where('storage_mode', 'local')
            ->where('created_at', '<=', $cutoff);

        $deleted = 0;
        $kept    = 0;
        $errors  = 0;

        foreach ($query->cursor() as $asset) {
            if ($this->isAssetOnDisk($assetsDir, $asset->asset_url)) {
                $kept++;
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('would delete %s (asset_url=%s)', $asset->id, $asset->asset_url));
                $deleted++;
                continue;
            }

            try {
                $this->service->delete($asset->id);
                $deleted++;
            } catch (Throwable $e) {
                $io->warning(sprintf('Failed to delete %s: %s', $asset->id, $e->getMessage()));
                $errors++;
            }
        }

        return $this->emitGcSummary($io, $deleted, $kept, $errors, $maxAgeDays, $dryRun);
    }

    /**
     * Reap `is_temporary=TRUE` rows older than `$olderThanHours` hours
     * ago. Same delete-and-count shape as the orphan sweep so the
     * operator sees a consistent summary, but the filter is age-based
     * (not storage-mode-based) and applies regardless of how the bytes
     * were stored — `local`, `data_url`, and `external` rows are all
     * eligible because the temp lifecycle is about the row, not the
     * on-disk asset.
     */
    private function runTempSweep(SymfonyStyle $io, int $olderThanHours, bool $dryRun): int
    {
        $cutoff = \Illuminate\Support\Carbon::now()->subHours($olderThanHours);

        $query = MediaAsset::query()
            ->where('is_temporary', true)
            ->where('created_at', '<=', $cutoff);

        $deleted = 0;
        $kept    = 0;
        $errors  = 0;

        foreach ($query->cursor() as $asset) {
            if ($dryRun) {
                $io->writeln(sprintf(
                    'would delete %s (is_temporary=true, created_at=%s)',
                    $asset->id,
                    $asset->created_at?->toIso8601String() ?? '(null)',
                ));
                $deleted++;
                continue;
            }

            try {
                $this->service->delete($asset->id);
                $deleted++;
            } catch (Throwable $e) {
                $io->warning(sprintf('Failed to delete %s: %s', $asset->id, $e->getMessage()));
                $errors++;
            }
        }

        $io->success(sprintf(
            '%s%d deleted, 0 kept, %d errors (temp older-than=%d hours, dry-run=%s)',
            $dryRun ? '[dry-run] ' : '',
            $deleted,
            $errors,
            $olderThanHours,
            $dryRun ? 'yes' : 'no',
        ));

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Local-mode URLs look like `/api/v1/assets/<token>.<ext>` — the
     * filename on disk is the last path segment. Anything else (data URL,
     * external CDN URL) is reported as "present" so we don't accidentally
     * try to stat a data: URI.
     */
    private function isAssetOnDisk(string $assetsDir, string $assetUrl): bool
    {
        $path = parse_url($assetUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || str_starts_with($path, 'data:')) {
            return true;
        }
        $filename = basename($path);
        if ($filename === '' || $filename === '/' || $filename === '.') {
            return true;
        }
        return is_file($assetsDir . '/' . $filename);
    }
}
