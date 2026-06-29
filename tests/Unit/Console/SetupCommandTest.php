<?php

declare(strict_types=1);

use Spora\Console\Commands\SetupCommand;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\Paths;
use Spora\Services\EmailTemplateLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function makeSetupTester(): CommandTester
{
    // The connection is already booted by tests/Pest.php beforeEach. Don't
    // resetBootState() — that would create a fresh in-memory SQLite that the
    // Auth (which is on the original connection) can't see.
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly(); // no-op: already booted

    $authService    = bootAuthLayer();
    $templateLoader = new EmailTemplateLoader(new Paths(BASE_PATH));
    $command = new SetupCommand(
        $db,
        new DatabaseSchemaInstaller(null, null),
        $authService,
        $templateLoader,
    );
    $command->setName('spora:setup');

    return new CommandTester($command);
}

it('seeds on a fresh install', function (): void {
    // Belt-and-suspenders: the in-memory transaction is rolled back
    // between tests, but make sure no admin user lingers from a previous run.
    Spora\Models\User::where('email', 'admin@spora.local')->delete();

    $tester = makeSetupTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('Running Spora database migrations')
        ->toContain('Schema is up to date')
        ->toContain('Fresh installation — running seeder...');
    // The seeder echoes its own progress to stdout, but those go to raw stdout,
    // not the OutputInterface. Verify the side effect instead: an admin user
    // was created.
    expect(Spora\Models\User::where('email', 'admin@spora.local')->exists())->toBeTrue();
});

it('skips seeding on a second run when users and agents exist', function (): void {
    // Pre-seed: create a user+agent so the second command sees an existing install.
    $auth = bootAuthLayer();
    $userId = $auth->register('existing@example.com', 'Password1!', 'Existing');

    Spora\Models\Agent::create([
        'user_id'   => $userId,
        'name'      => 'Existing Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $tester = makeSetupTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('Schema is up to date')
        ->toContain('Existing installation detected. Skipping seeding.');
});
