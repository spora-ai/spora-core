<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Console\Commands\SeedCommand;
use Spora\Core\Database;
use Spora\Services\EmailTemplateLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function makeSeedTester(): CommandTester
{
    // The connection is already booted by tests/Pest.php beforeEach; do NOT
    // resetBootState() here, that would create a fresh in-memory SQLite that
    // the factory closure's Auth can't see.
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly(); // no-op: already booted

    $templateLoader = new EmailTemplateLoader();
    $authFactory = static fn(): AuthService => bootAuthLayer();

    $command = new SeedCommand($db, $authFactory, $templateLoader);
    $command->setName('db:seed');

    return new CommandTester($command);
}

it('runs the seeder and reports success', function (): void {
    Spora\Models\User::where('email', 'admin@spora.local')->delete();

    $tester = makeSeedTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('Starting database seeder...')
        ->toContain('Seeding finished successfully.');
    // Side effect: the admin user was created.
    expect(Spora\Models\User::where('email', 'admin@spora.local')->exists())->toBeTrue();
});
