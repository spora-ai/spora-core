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
 * `bin/spora-build openapi:generate` — emits the OpenAPI 3.0 spec from
 * `RouteDefinitions` and writes it to the supplied path.
 *
 * The actual build lives in {@see Generate::regenerate()};
 * this class is the Symfony Console wrapper around it.
 */
#[AsCommand(
    name: 'openapi:generate',
    description: 'Generate the OpenAPI 3.0 spec (openapi.json) from RouteDefinitions.',
)]
final class OpenApiGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'output',
            InputArgument::OPTIONAL,
            'Where to write the JSON document (relative to BASE_PATH or absolute).',
            'openapi.json',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('output');
        if ($path !== '' && $path[0] !== '/') {
            $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
            $path = rtrim((string) $base, '/') . '/' . ltrim($path, '/');
        }
        $wrote = Generate::regenerate($path);
        if ($wrote === Command::SUCCESS) {
            $io->success('Done.');
        } else {
            $io->error('Failed — see error above.');
        }
        return $wrote;
    }
}
