<?php

declare(strict_types=1);

namespace Spora\Console\Commands;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Deletes every row of the `settings` table, sending each setting back to
 * whatever `config.php` / `.env` / the registry default says.
 *
 * The recovery path for a lost or rotated `SPORA_SECRET_KEY`: a row encrypted
 * with a key nobody holds is not decryptable, `Config::get()` throws for it,
 * and every page that reads it fails until the row is gone. This is the way
 * out that needs neither a working application nor a SQLite client.
 *
 * A CLI rather than a UI button, deliberately. Recovery has to work when the
 * app will not boot, and a button in the settings page can destroy every
 * configured credential in one mis-click — the mail password, the Mercure JWT
 * key, every stored token — with nothing on screen saying which values just
 * disappeared. Here the rows are printed first and a confirm stands between
 * the operator and the delete.
 */
#[AsCommand(
    name: 'settings:reset',
    description: 'Delete every stored setting, falling back to config.php / .env / the registry defaults.',
)]
final class SettingsResetCommand extends Command
{
    public function __construct(private readonly Database $database)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the interactive confirm prompt')
            ->setHelp(<<<HELP
Deletes every row of the <info>settings</info> table. Each setting then resolves from
<comment>config.php</comment> / <comment>.env</comment> / its registry default again — the resolution
order is unchanged, only the database layer is dropped.

Use it when a stored value can no longer be read: a lost or rotated
<comment>SPORA_SECRET_KEY</comment> leaves every secret row undecryptable, and
<info>Config::get()</info> throws for those keys, so the pages that read them fail.

The rows are listed before the prompt; <info>--force</info> skips it for non-interactive
runs. Nothing is archived — the values are gone, not moved.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        try {
            $this->database->bootDatabaseConnectionOnly();
            $rows = $this->storedRows();

            if ($rows === []) {
                $io->success('No stored settings. Nothing to reset.');

                return Command::SUCCESS;
            }

            foreach ($rows as $row) {
                $io->writeln(sprintf('  <comment>%s</comment> / %s', $row['group'], $row['key']));
            }
            $io->writeln(sprintf(
                'About to remove %d stored setting(s). Every value falls back to <comment>config.php</comment> / <comment>.env</comment>.',
                count($rows),
            ));

            if (!$force && !$io->confirm('Remove them all?', false)) {
                $io->writeln('Aborted. No changes made. (Pass --force to skip the prompt in non-interactive runs.)');

                return Command::FAILURE;
            }

            // No key filter: a row left behind by an uninstalled plugin has no
            // declared setting to address it by, and it is exactly the row that
            // would otherwise survive a "reset".
            Capsule::table('settings')->delete();
        } catch (Throwable $e) {
            $io->error('Settings reset failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Removed %d stored setting(s). They resolve from <comment>config.php</comment> / <comment>.env</comment> again.',
            count($rows),
        ));

        return Command::SUCCESS;
    }

    /**
     * Every row in the table, declared or not. `SettingsRepository::allForGroup()`
     * would hide the ones whose key the registry no longer declares, and a list
     * the operator cannot trust is worse than none at all in front of a delete.
     *
     * @return list<array{group: string, key: string}>
     */
    private function storedRows(): array
    {
        if (!Capsule::schema()->hasTable('settings')) {
            return [];
        }

        $rows = [];
        foreach (Capsule::table('settings')->orderBy('group_key')->orderBy('setting_key')->get(['group_key', 'setting_key']) as $row) {
            $rows[] = ['group' => (string) $row->group_key, 'key' => (string) $row->setting_key];
        }

        return $rows;
    }
}
