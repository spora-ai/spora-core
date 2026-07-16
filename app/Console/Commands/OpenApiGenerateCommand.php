<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use OpenApi\Annotations\OpenApi;
use Spora\OpenApi\RouteToOpenApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Usage:
 *   php bin/spora spora:openapi --output=openapi.json
 *   php bin/spora spora:openapi --check
 *
 * `--check` exits non-zero if the committed spec is stale so it can be wired as a CI step
 * that fails the build when routes change without a regenerated spec.
 *
 * The builder is built lazily inside `execute()` so the command doesn't pull the rest of
 * the DI graph (orchestrator, DB, secret key) at registration time — `spora:openapi` only
 * needs the route table and the swagger-php library.
 */
#[AsCommand(
    name: 'spora:openapi',
    description: 'Generate the OpenAPI 3.0 specification (openapi.json) from RouteDefinitions.',
)]
final class OpenApiGenerateCommand extends Command
{
    private readonly RouteToOpenApi $builder;

    public function __construct()
    {
        parent::__construct();
        $this->builder = new RouteToOpenApi();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Where to write the JSON document (relative to BASE_PATH or absolute).',
                'openapi.json',
            )
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Exit non-zero if the committed spec is stale; do not write.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $outputPath = $this->resolvePath((string) $input->getOption('output'));
        $checkOnly = (bool) $input->getOption('check');

        $openapi = $this->builder->build();
        $json    = $this->serialise($openapi);

        if ($checkOnly) {
            if (!is_file($outputPath)) {
                $io->error(sprintf('No committed spec at %s to compare against.', $outputPath));
                return Command::FAILURE;
            }
            $committed = file_get_contents($outputPath);
            if ($committed === $json) {
                $io->success(sprintf('Spec at %s is up to date.', $outputPath));
                return Command::SUCCESS;
            }
            $io->error(sprintf(
                'Spec at %s is stale. Regenerate with `php bin/spora spora:openapi`.',
                $outputPath,
            ));
            return Command::FAILURE;
        }

        $written = file_put_contents($outputPath, $json);
        if ($written === false) {
            $io->error(sprintf('Failed to write spec to %s.', $outputPath));
            return Command::FAILURE;
        }

        $io->success(sprintf('Wrote %d bytes to %s.', $written, $outputPath));
        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        $base = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return rtrim((string) $base, '/') . '/' . ltrim($path, '/');
    }

    private function serialise(OpenApi $openapi): string
    {
        return json_encode(
            $openapi,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }
}
