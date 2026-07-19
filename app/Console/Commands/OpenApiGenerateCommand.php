<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use JsonException;
use OpenApi\Annotations\OpenApi;
use Spora\OpenApi\RouteToOpenApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `spora:openapi` — emits the OpenAPI 3.0 spec from `RouteDefinitions`.
 *
 * Two entry paths share the work:
 *  - `bin/spora spora:openapi [--output=…] [--check]` (this class, with full Symfony
 *    Console integration; `--check` makes it a CI-style drift guard).
 *  - `bin/spora-build openapi:generate|openapi:check` (build-time companion; same
 *    static helpers, but skips the Kernel/DI boot so a clean checkout with no
 *    `storage/secret.key` can still produce the spec).
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
                'Exit non-zero if the reference spec differs from a fresh regeneration; do not write.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $outputPath = $this->resolvePath((string) $input->getOption('output'));
        $checkOnly  = (bool) $input->getOption('check');
        $json       = $this->serialise($this->builder->build());

        if ($checkOnly) {
            return $this->runCheck($io, $outputPath, $json);
        }

        return $this->writeSpec($io, $outputPath, $json);
    }

    private function runCheck(SymfonyStyle $io, string $outputPath, string $json): int
    {
        if (!is_file($outputPath)) {
            $io->error(sprintf('No reference spec at %s to compare against.', $outputPath));
            return Command::FAILURE;
        }
        if ((string) file_get_contents($outputPath) === $json) {
            $io->success(sprintf('Spec at %s is up to date.', $outputPath));
            return Command::SUCCESS;
        }

        $io->error(sprintf(
            'Spec at %s is stale. Regenerate with `php bin/spora spora:openapi`.',
            $outputPath,
        ));
        return Command::FAILURE;
    }

    private function writeSpec(SymfonyStyle $io, string $outputPath, string $json): int
    {
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
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Bypasses `bin/spora` (and therefore the Kernel/DI/secret-key boot) so a
     * CI step that lacks `storage/secret.key` can still produce the artifact.
     * Returns `Command::SUCCESS`/`Command::FAILURE` so the entry path can be
     * used directly by CI.
     *
     * @return int Command::SUCCESS on write, Command::FAILURE on I/O or encode failure.
     */
    public static function regenerate(string $outputPath): int
    {
        try {
            $json = (new RouteToOpenApi())->build();
            $serialised = self::encode($json);
        } catch (JsonException $e) {
            fwrite(STDERR, sprintf("Failed to encode OpenAPI document as JSON: %s\n", $e->getMessage()));
            return Command::FAILURE;
        }

        $written = @file_put_contents($outputPath, $serialised);
        if ($written === false) {
            fwrite(STDERR, sprintf("Failed to write spec to %s.\n", $outputPath));
            return Command::FAILURE;
        }

        fwrite(STDOUT, sprintf("Wrote %d bytes to %s.\n", $written, $outputPath));
        return Command::SUCCESS;
    }

    /**
     * Bypasses `bin/spora` (and therefore the Kernel/DI/secret-key boot) so a
     * CI step that lacks `storage/secret.key` can still verify the reference
     * spec is up to date.
     *
     * Returns `Command::SUCCESS` when the freshly-generated spec matches the
     * reference file, `Command::FAILURE` otherwise (or when the reference is
     * missing, or when JSON encoding fails).
     */
    public static function checkAgainstFile(string $referencePath): int
    {
        try {
            $json = (new RouteToOpenApi())->build();
            $serialised = self::encode($json);
        } catch (JsonException $e) {
            fwrite(STDERR, sprintf("Failed to encode OpenAPI document as JSON: %s\n", $e->getMessage()));
            return Command::FAILURE;
        }

        if (!is_file($referencePath)) {
            fwrite(STDERR, sprintf("No reference spec at %s to compare against.\n", $referencePath));
            return Command::FAILURE;
        }
        $committed = file_get_contents($referencePath);
        if ($committed === $serialised) {
            return Command::SUCCESS;
        }

        fwrite(STDERR, sprintf(
            "Spec at %s is stale. Regenerate with `composer openapi`.\n",
            $referencePath,
        ));
        return Command::FAILURE;
    }

    private static function encode(OpenApi $openapi): string
    {
        return json_encode(
            $openapi,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
