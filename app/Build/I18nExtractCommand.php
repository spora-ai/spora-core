<?php

declare(strict_types=1);

namespace Spora\Build;

use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/spora-build i18n:extract` — regenerates `locales/en.json` from the
 * English literals in the source, as a flat identity map.
 *
 * Never hand-edit the result: `i18n:check` fails on drift, so an edit
 * there is overwritten by the next run. Add the string to the source and
 * re-run; `composer i18n:extract` is this command.
 */
#[AsCommand(
    name: 'i18n:extract',
    description: 'Regenerate locales/en.json from the English literals in the source.',
)]
final class I18nExtractCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $harvested = (new CatalogHarvester(CatalogFiles::basePath()))->harvest();
        $identity = CatalogHarvester::identityMap($harvested);

        try {
            $serialised = CatalogFiles::encode($identity);
        } catch (JsonException $e) {
            $io->error(sprintf('Failed to encode the catalogue: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $written = file_put_contents(CatalogFiles::englishPath(), $serialised);
        if ($written === false) {
            $io->error('Failed to write locales/en.json.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Wrote %d messages to locales/en.json.', count($identity)));

        return Command::SUCCESS;
    }
}
