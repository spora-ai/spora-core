<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Console\Commands\RepairAdminCommand;
use Spora\Core\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function makeRepairAdminTester(): CommandTester
{
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    $command = new RepairAdminCommand($db);
    $command->setName('db:repair-admin');
    return new CommandTester($command);
}

function insertLegacyAdmin(string $email = 'admin@spora.local'): int
{
    $now = date('Y-m-d H:i:s');
    return Capsule::table('users')->insertGetId([
        'email'        => $email,
        'password'     => password_hash('password', PASSWORD_BCRYPT),
        'username'     => 'admin',
        'status'       => 0,
        'verified'     => 0,
        'resettable'   => 1,
        'roles_mask'   => 0,
        'registered'   => time(),
        'last_login'   => null,
        'force_logout' => 0,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
}

it('promotes a legacy admin row to verified + Role::ADMIN + status=1', function (): void {
    insertLegacyAdmin();

    $tester = makeRepairAdminTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain("Repaired admin row 'admin@spora.local'")
        ->toContain('verified:   0 → 1')
        ->toContain('roles_mask: 0 → ' . Role::ADMIN)
        ->toContain('status:     0 → 1');

    $user = Spora\Models\User::where('email', 'admin@spora.local')->firstOrFail();
    expect($user->verified)->toBe(1)
        ->and($user->roles_mask)->toBe(Role::ADMIN)
        ->and($user->status)->toBe(1);
});

it('preserves existing role bits and only adds Role::ADMIN', function (): void {
    insertLegacyAdmin();
    Capsule::table('users')->where('email', 'admin@spora.local')->update([
        'roles_mask' => Role::ADMIN | 0b10000000,
    ]);

    $tester = makeRepairAdminTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);

    $user = Spora\Models\User::where('email', 'admin@spora.local')->firstOrFail();
    expect($user->verified)->toBe(1)
        ->and($user->status)->toBe(1)
        ->and(($user->roles_mask & Role::ADMIN))->toBe(Role::ADMIN)
        ->and(($user->roles_mask & 0b10000000))->toBe(0b10000000);
});

it('is idempotent — a second run reports no change', function (): void {
    insertLegacyAdmin();

    makeRepairAdminTester()->execute([]);

    $tester = makeRepairAdminTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('verified:   1 → 1')
        ->toContain('roles_mask: ' . Role::ADMIN . ' → ' . Role::ADMIN)
        ->toContain('status:     1 → 1');
});

it('refuses to act when the admin row is missing (security)', function (): void {
    Spora\Models\User::where('email', 'admin@spora.local')->delete();

    $tester = makeRepairAdminTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())
        ->toContain("No user with email 'admin@spora.local' exists")
        ->toContain('db:seed');

    expect(Spora\Models\User::where('email', 'admin@spora.local')->exists())->toBeFalse();
});

it('repairs a non-default admin email when provided as an argument', function (): void {
    insertLegacyAdmin('lead@spora.local');

    $tester = makeRepairAdminTester();
    $tester->execute(['email' => 'lead@spora.local']);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain("Repaired admin row 'lead@spora.local'");

    expect((int) Spora\Models\User::where('email', 'lead@spora.local')->value('verified'))->toBe(1);
});
