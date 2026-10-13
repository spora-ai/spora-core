<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Console\Commands\SettingsResetCommand;
use Spora\Core\Config;
use Spora\Core\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    TestDatabaseFactory::boot();
    // The MySQL/MariaDB CI databases are shared for the whole run and are only
    // dropped when a test dirties the schema, so a row written by one test here
    // is still present for the next one and the insert collides on the unique
    // index. SQLite gets a fresh `:memory:` every time, which is why this only
    // ever showed up on those engines.
    Capsule::table('settings')->delete();
});

afterEach(fn() => Database::resetBootState());

function makeSettingsResetTester(): CommandTester
{
    // `bootDatabaseConnectionOnly()` is a no-op once `TestDatabaseFactory` has
    // booted, so this command sees the same `:memory:` database as the test.
    $command = new SettingsResetCommand(
        new Database(new Config(['SPORA_DB_DRIVER' => 'sqlite', 'SPORA_DB_PATH' => ':memory:'])),
    );
    $command->setName('settings:reset');

    return new CommandTester($command);
}

function storeSettingsRow(string $group, string $key, string $value = 'stored'): void
{
    Capsule::table('settings')->insert([
        'group_key'   => $group,
        'setting_key' => $key,
        'value'       => $value,
        'encrypted'   => false,
        'updated_by'  => null,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);
}

function countSettingsRows(): int
{
    return Capsule::table('settings')->count();
}

it('empties the settings table', function (): void {
    storeSettingsRow('mail', 'SPORA_MAIL_HOST', 'smtp.example.com');
    storeSettingsRow('framework', 'SPORA_APP_NAME', 'Spora Cloud');

    $tester = makeSettingsResetTester();
    $tester->execute(['--force' => true]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS)
        ->and(countSettingsRows())->toBe(0);
});

it('is a no-op on an already-empty table', function (): void {
    $tester = makeSettingsResetTester();
    $tester->execute(['--force' => true]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('Nothing to reset');

    // Second run against the same, still-empty table.
    $again = makeSettingsResetTester();
    $again->execute(['--force' => true]);

    expect($again->getStatusCode())->toBe(Command::SUCCESS)
        ->and(countSettingsRows())->toBe(0);
});

it('aborts on a declined confirm and changes nothing', function (): void {
    storeSettingsRow('mail', 'SPORA_MAIL_HOST');
    storeSettingsRow('security', 'SPORA_MERCURE_JWT_KEY');

    $tester = makeSettingsResetTester();
    $tester->setInputs(['no']);
    $tester->execute([], ['interactive' => true]);

    expect($tester->getStatusCode())->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Aborted')->toContain('--force')
        ->and(countSettingsRows())->toBe(2);
});

it('aborts without --force on a non-interactive run', function (): void {
    storeSettingsRow('mail', 'SPORA_MAIL_HOST');

    $tester = makeSettingsResetTester();
    $tester->execute([], ['interactive' => false]);

    expect($tester->getStatusCode())->toBe(Command::FAILURE)
        ->and(countSettingsRows())->toBe(1);
});

it('skips the prompt under --force', function (): void {
    storeSettingsRow('mail', 'SPORA_MAIL_HOST');

    $tester = makeSettingsResetTester();
    $tester->execute(['--force' => true], ['interactive' => false]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->not->toContain('yes/no')
        ->and(countSettingsRows())->toBe(0);
});

it('accepts the short -f alias', function (): void {
    storeSettingsRow('mail', 'SPORA_MAIL_HOST');

    $tester = makeSettingsResetTester();
    $tester->execute(['-f' => true], ['interactive' => false]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS)
        ->and(countSettingsRows())->toBe(0);
});

it('prints the group and key of every row it removes', function (): void {
    storeSettingsRow('mail', 'SPORA_MAIL_HOST');
    storeSettingsRow('framework', 'SPORA_APP_NAME');

    $tester = makeSettingsResetTester();
    $tester->execute(['--force' => true]);

    $display = $tester->getDisplay();
    expect($display)
        ->toContain('mail / SPORA_MAIL_HOST')
        ->toContain('framework / SPORA_APP_NAME')
        // The listing is printed before the delete, so the count is known at that point.
        ->toContain('About to remove 2 stored setting(s)')
        ->toContain('Removed 2 stored setting(s).');
});

it('removes and lists rows the registry no longer declares', function (): void {
    storeSettingsRow('plugin-acme', 'SPORA_ACME_API_KEY');

    $tester = makeSettingsResetTester();
    $tester->execute(['--force' => true]);

    expect($tester->getDisplay())->toContain('plugin-acme / SPORA_ACME_API_KEY')
        ->and(countSettingsRows())->toBe(0);
});

it('does not prompt when there is nothing to lose', function (): void {
    $tester = makeSettingsResetTester();
    $tester->execute([], ['interactive' => false]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->not->toContain('yes/no');
});

/**
 * A fresh clone has no migrated schema and `bin/spora db:reset` drops the
 * table, so "no settings table" is a reachable state on an install that is
 * already broken in some other way. Erroring here would replace one failure
 * with a less obvious one.
 */
it('reports nothing to reset when the settings table does not exist', function (): void {
    $migration = require BASE_PATH . '/database/migrations/0092_create_settings_table.php';

    Capsule::schema()->dropIfExists('settings');

    try {
        $tester = makeSettingsResetTester();
        $tester->execute(['--force' => true], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS)
            ->and($tester->getDisplay())->toContain('Nothing to reset');
    } finally {
        $migration->up();
    }
});

it('help text documents what is lost and how to skip the prompt', function (): void {
    $command = new SettingsResetCommand(new Database(new Config()));

    $help = $command->getHelp();

    expect($help)
        ->toContain('SPORA_SECRET_KEY')
        ->toContain('--force');
});
