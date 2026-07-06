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
 * A row is orphaned when its on-disk asset is gone (GC'd by `assets:gc`,
 * manually deleted, or never promoted because it landed in
 * `storage_mode = external` and the CDN URL 404'd — though the latter is
 * filtered out by the `local`-only filter below).
 *
 * Rows whose `storage_mode != 'local'` are skipped: external rows are
 * allowed to outlive their CDN because the row IS the durable record.
 */
#[AsCommand(
    name: 'media:gc',
    description: 'Delete media_assets rows whose on-disk file is missing.',
)]
final class MediaArchiveGcCommand extends Command
{
    public function __construct(
        private readonly MediaArchiveService $service,
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
                'Only consider rows older than this many days (0 = all).',
                '30',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print what would be deleted without actually deleting rows.',
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
