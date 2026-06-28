<?php

declare(strict_types=1);

use Spora\Console\Commands\InstallCommand;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function makeInstallTester(Database $db): CommandTester
{
    Database::resetBootState();
    $command = new InstallCommand($db, new DatabaseSchemaInstaller(null, null));
    $command->setName('spora:install');

    return new CommandTester($command);
}

function makeInstallDb(): Database
{
    Database::resetBootState();
    return new Database([
        'db_driver'   => 'sqlite',
        'db_path'     => ':memory:',
        'db_host'     => null,
        'db_port'     => null,
        'db_name'     => null,
        'db_user'     => null,
        'db_password' => null,
    ]);
}

it('boots the DB, runs migrations, and reports success', function (): void {
    $tester = makeInstallTester(makeInstallDb());

    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('Running Spora database migrations')
        ->toContain('Schema is up to date');
});
