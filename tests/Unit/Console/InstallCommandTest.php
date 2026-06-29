<?php

declare(strict_types=1);

use Spora\Console\Commands\InstallCommand;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function makeInstallTester(Database $db): CommandTester
{
    Database::resetBootState();
    $command = new InstallCommand($db, new DatabaseSchemaInstaller(null, null), new Paths(BASE_PATH));
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

it('generates a fresh storage/secret.key on first run', function (): void {
    $keyFile = BASE_PATH . '/storage/secret.key';
    $existed = is_file($keyFile);
    $existedContents = $existed ? file_get_contents($keyFile) : null;

    if ($existed) {
        @unlink($keyFile);
    }

    // Seed public/dist/index.html if missing so the post-migration check passes.
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
        expect($tester->getDisplay())->toContain('Generated new secret key at');
        expect(is_file($keyFile))->toBeTrue();
        expect(filesize($keyFile))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    } finally {
        if ($created) {
            @unlink($indexFile);
            @rmdir($distDir);
        }
        if (! $existed) {
            @unlink($keyFile);
        } else {
            file_put_contents($keyFile, $existedContents);
        }
    }
});
