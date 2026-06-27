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
    // Fresh CI trees have no rendered UI; seed a placeholder so the C4.4
    // dist guard inside InstallCommand doesn't fail this happy-path test.
    $distDir  = BASE_PATH . '/public/dist';
    $indexFile = $distDir . '/index.html';
    $created = false;
    if (! is_file($indexFile)) {
        if (! is_dir($distDir)) {
            mkdir($distDir, 0o755, true);
        }
        file_put_contents($indexFile, '<!doctype html><title>test</title>');
        $created = true;
    }

    try {
        $tester = makeInstallTester(makeInstallDb());

        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())
            ->toContain('Running Spora database migrations')
            ->toContain('Schema is up to date');
    } finally {
        if ($created) {
            @unlink($indexFile);
            @rmdir($distDir);
        }
    }
});

it('fails with a helpful hint when public/dist/index.html is missing', function (): void {
    $indexFile = BASE_PATH . '/public/dist/index.html';

    if (is_file($indexFile)) {
        // Local dev trees ship a rendered UI; temporarily move it aside.
        rename($indexFile, $indexFile . '.bak');
    }

    try {
        $tester = makeInstallTester(makeInstallDb());

        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        $display = $tester->getDisplay();
        expect($display)
            ->toContain('public/dist/index.html is missing')
            ->toContain('composer install spora-ai/spora-frontend');
        // Guard must fire before migrations — otherwise the operator sees
        // a misleading "Schema is up to date" alongside the broken UI.
        expect($display)->not->toContain('Schema is up to date');
        expect($display)->not->toContain('Running Spora database migrations');
    } finally {
        if (is_file($indexFile . '.bak')) {
            rename($indexFile . '.bak', $indexFile);
        }
    }
});
