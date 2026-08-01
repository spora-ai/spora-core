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
        ->toContain('Fresh installation — running seeder...');
    // The seeder echoes its own progress to stdout, but those go to raw stdout,
    // not the OutputInterface. Verify the side effect instead: an admin user
    // was created.
    $user = Spora\Models\User::where('email', 'admin@spora.local')->firstOrFail();
    expect($user->verified)->toBe(1)
        ->and($user->roles_mask)->toBe(Role::ADMIN)
        ->and($user->status)->toBe(1);
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

it('repairs an existing seeded admin that is unverified or missing the admin role', function (): void {
    // Simulate an old install (pre-PR #133) whose seeded admin was persisted
    // with verified=0 and no admin role. Reset the admin row in place to that
    // state, then re-run migration 0064 directly — the same code path that
    // would fire on a container boot of a persistent DB that just upgraded
    // to a spora-core version shipping this migration.
    $existing = Capsule::table('users')->where('email', 'admin@spora.local')->first();
    if ($existing === null) {
        $userId = Capsule::table('users')->insertGetId([
            'email'         => 'admin@spora.local',
            'password'      => password_hash('password', PASSWORD_BCRYPT),
            'username'      => 'admin',
            'status'        => 0,
            'verified'      => 0,
            'resettable'    => 1,
            'roles_mask'    => 0,
            'registered'    => time(),
            'last_login'    => null,
            'force_logout'  => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        Spora\Models\Agent::create([
            'user_id'   => $userId,
            'name'      => 'Existing Agent',
            'max_steps' => 5,
            'is_active' => true,
        ]);
    } else {
        Capsule::table('users')
            ->where('email', 'admin@spora.local')
            ->update(['verified' => 0, 'status' => 0, 'roles_mask' => 0]);
    }

    $migration = require __DIR__ . '/../../../database/migrations/0064_repair_seeded_admin.php';
    $migration->up();

    $user = Spora\Models\User::where('email', 'admin@spora.local')->firstOrFail();
    expect($user->verified)->toBe(1)
        ->and($user->roles_mask)->toBe(Role::ADMIN)
        ->and($user->status)->toBe(1);
});
