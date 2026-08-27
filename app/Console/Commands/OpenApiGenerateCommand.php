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

    public function __construct(?RouteToOpenApi $builder = null)
    {
        parent::__construct();
        $this->builder = $builder ?? new RouteToOpenApi();
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
        // The `info.version` field is generated from `git describe` at
        // regeneration time and drifts on every commit (the version string
        // encodes the current HEAD offset from the last tag). It's pure
        // informational metadata, not part of the API contract — strip it
        // on both sides before comparing so a freshly regenerated spec
        // doesn't always fail the drift check.
        $normalisedFresh = self::withoutVersionField($json);
        $normalisedRef   = self::withoutVersionField((string) file_get_contents($outputPath));
        if ($normalisedFresh === $normalisedRef) {
            $io->success(sprintf('Spec at %s is up to date.', $outputPath));
            return Command::SUCCESS;
        }

        $io->error(sprintf(
            'Spec at %s is stale. Regenerate with `php bin/spora spora:openapi`.',
            $outputPath,
        ));
        return Command::FAILURE;
    }

    /**
     * Remove the `info.version` JSON object key so the drift check does
     * not report a false-positive on the dynamic git-describe output.
     * Falls back to the original string when the document does not parse.
     */
    private static function withoutVersionField(string $json): string
    {
        try {
            $doc = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $json;
        }
        if (!is_array($doc) || !isset($doc['info']['version'])) {
            return $json;
        }
        unset($doc['info']['version']);
        return (string) json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
    public static function regenerate(string $outputPath, array $config = []): int
    {
        try {
            $json = (new RouteToOpenApi())->build(self::resolveConfigForBuild($config));
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
        $serialised = '';
        $error      = null;
        try {
            $serialised = self::encode((new RouteToOpenApi())->build(self::resolveConfigForBuild()));
        } catch (JsonException $e) {
            $error = sprintf("Failed to encode OpenAPI document as JSON: %s\n", $e->getMessage());
        }

        $committed = is_file($referencePath) ? (string) file_get_contents($referencePath) : null;
        if ($committed === null) {
            $error = sprintf("No reference spec at %s to compare against.\n", $referencePath);
        } elseif ($error === null && self::withoutVersionField($committed) !== self::withoutVersionField($serialised)) {
            // The `info.version` field is generated from `git describe` at
            // regeneration time and drifts on every commit. It's pure
            // informational metadata, not part of the API contract — strip
            // it on both sides before comparing so a freshly regenerated
            // spec doesn't always fail the drift check.
            $error = sprintf(
                "Spec at %s is stale. Regenerate with `composer openapi`.\n",
                $referencePath,
            );
        }

        if ($error !== null) {
            fwrite(STDERR, $error);
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    private static function encode(OpenApi $openapi): string
    {
        return json_encode(
            $openapi,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Reconcile the build-time config snapshot with the runtime env vars
     * read by `bin/spora-build` (which skips Kernel boot).
     *
     * The build variant defaults `allow_group_creation` to `true`
     * (matching `config.php`) — operators who set
     * `SPORA_ALLOW_GROUP_CREATION=false` re-run `bin/spora spora:openapi`
     * (full Kernel) to refresh the spec under their config.
     *
     * @param array<string, mixed> $callerConfig
     * @return array<string, mixed>
     */
    private static function resolveConfigForBuild(array $callerConfig = []): array
    {
        $envValue = getenv('SPORA_ALLOW_GROUP_CREATION');
        if ($envValue === false || $envValue === '') {
            return $callerConfig;
        }
        $callerConfig['allow_group_creation'] = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        return $callerConfig;
    }
}
