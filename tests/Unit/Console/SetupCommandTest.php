<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Console\Commands\SetupCommand;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\ToolConfigService;
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

    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    $toolConfig = new ToolConfigService($security, $logger, [
        Spora\Tools\TimeTool::class,
        Spora\Tools\CalculatorTool::class,
        Spora\Tools\ReadUrlTool::class,
        Spora\Tools\UserInfoTool::class,
        Spora\Tools\HandoverTool::class,
    ]);
    $importer = new AgentTemplateImporter(
        $toolConfig,
        new PluginLoader([]),
        new Paths(BASE_PATH),
    );

    $command = new SetupCommand(
        $db,
        new DatabaseSchemaInstaller(null, null),
        $authService,
        $templateLoader,
        $importer,
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
        ->toContain('Running database seeder...');
    // The seeder echoes its own progress to stdout, but those go to raw stdout,
    // not the OutputInterface. Verify the side effect instead: an admin user
    // was created.
    $user = Spora\Models\User::where('email', 'admin@spora.local')->firstOrFail();
    expect($user->verified)->toBe(1)
        ->and($user->roles_mask)->toBe(Role::ADMIN)
        ->and($user->status)->toBe(1);
});

it('reconciles a pre-existing admin row on a second run (persistent volume)', function (): void {
    // Simulate a persistent volume that already has a user + agent from a
    // previous spora:setup. spora:setup must still run the seeder so the admin
    // roles_mask / status are re-asserted — the seeder is the repair path for
    // installs whose admin was persisted with a broken role/status by an older
    // spora-core release.
    $auth = bootAuthLayer();
    $userId = $auth->register('existing@example.com', 'Password1!', 'Existing');

    Spora\Models\Agent::create([
        'user_id'   => $userId,
        'name'      => 'Existing Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    // Demote the admin to a state an older spora-core release might have
    // persisted: unverified, suspended, no admin role.
    Capsule::table('users')
        ->where('email', 'admin@spora.local')
        ->update(['verified' => 0, 'status' => 0, 'roles_mask' => 0]);

    $tester = makeSetupTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    $display = $tester->getDisplay();
    expect($display)
        ->toContain('Schema is up to date')
        ->toContain('Running database seeder...');
    expect(str_contains($display, 'Existing installation detected. Skipping seeding.'))->toBeFalse();

    $admin = Spora\Models\User::where('email', 'admin@spora.local')->firstOrFail();
    expect($admin->roles_mask)->toBe(Role::ADMIN)
        ->and($admin->status)->toBe(1)
        ->and($admin->verified)->toBe(1);
});
