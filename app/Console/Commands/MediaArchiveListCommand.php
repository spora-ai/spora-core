<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use DateTimeImmutable;
use Exception;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator CLI for browsing archived media. Mirrors `GET /api/v1/media`
 * with a tabular layout; `--json` switches to the raw API envelope so the
 * output can be piped into `jq`.
 */
#[AsCommand(
    name: 'media:list',
    description: 'List archived media assets (matches GET /api/v1/media).',
)]
final class MediaArchiveListCommand extends Command
{
    public function __construct(
        private readonly MediaArchiveService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Filter by agent ID.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by media type (image|audio|video|document|unknown).')
            ->addOption('plugin', null, InputOption::VALUE_REQUIRED, 'Filter by plugin slug.')
            ->addOption('tool', null, InputOption::VALUE_REQUIRED, 'Filter by tool name.')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Filter by created_at >= (any strtotime-compatible string).')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Substring search across prompt, asset_url, source_url.')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number.', '1')
            ->addOption('per-page', null, InputOption::VALUE_REQUIRED, 'Items per page (max 100).', (string) ListMediaQuery::PER_PAGE_DEFAULT)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the API envelope as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mediaType = null;
        $typeOpt = $input->getOption('type');
        if (is_string($typeOpt) && $typeOpt !== '') {
            $mediaType = MediaType::tryFrom(strtolower($typeOpt));
            if ($mediaType === null) {
                $io->error(sprintf(
                    'Unknown media type "%s"; expected one of: image, audio, video, document, unknown.',
                    $typeOpt,
                ));
                return Command::FAILURE;
            }
        }

        $from = null;
        $sinceOpt = $input->getOption('since');
        if (is_string($sinceOpt) && $sinceOpt !== '') {
            try {
                $from = new DateTimeImmutable($sinceOpt);
            } catch (Exception) {
                $io->error(sprintf('Could not parse --since "%s".', $sinceOpt));
                return Command::FAILURE;
            }
        }

        $page = (int) $input->getOption('page');
        $perPage = (int) $input->getOption('per-page');

        $query = new ListMediaQuery(
            mediaType: $mediaType,
            agentId: $this->asInt($input->getOption('agent')),
            pluginSlug: $this->asString($input->getOption('plugin')),
            toolName: $this->asString($input->getOption('tool')),
            from: $from,
            search: $this->asString($input->getOption('search')),
            page: $page,
            perPage: $perPage,
        );

        $paginated = $this->service->list($query);

        if ($input->getOption('json')) {
            $payload = [
                'data' => [
                    'assets'   => array_map(
                        static fn($asset) => \Spora\Http\MediaArchiveController::serialize($asset),
                        $paginated->items(),
                    ),
                    'page'     => $paginated->currentPage(),
                    'perPage'  => $paginated->perPage(),
                    'total'    => $paginated->total(),
                    'lastPage' => $paginated->lastPage(),
                ],
            ];
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($paginated->items() as $asset) {
            $rows[] = [
                $asset->id,
                $asset->media_type ?? 'unknown',
                $asset->mime_type ?? '?',
                $asset->byte_size !== null ? (string) $asset->byte_size : '-',
                $asset->storage_mode,
                $asset->asset_url,
                $asset->created_at?->toIso8601String() ?? '-',
            ];
        }

        if ($rows === []) {
            $io->writeln('<info>No archived media matched the filters.</info>');
            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Type', 'MIME', 'Bytes', 'Mode', 'Asset URL', 'Created'],
            $rows,
        );
        $io->writeln(sprintf(
            '<info>Page %d / %d — %d total assets.</info>',
            $paginated->currentPage(),
            $paginated->lastPage(),
            $paginated->total(),
        ));

        return Command::SUCCESS;
    }

    private function asInt(mixed $value): ?int
    {
        return is_string($value) && $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
