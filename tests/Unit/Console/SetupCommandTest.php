<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Console\Commands\SetupCommand;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\Mail\MailTemplateRenderer;
use Spora\Services\Mail\MailTemplateSyncService;
use Spora\Services\MailTemplateService;
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
    $mailService    = new MailTemplateService(MailTemplateRenderer::createDefault());
    $mailSync       = new MailTemplateSyncService($templateLoader, $mailService);

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
        $mailSync,
        $importer,
    );
    $command->setName('spora:setup');

    return new CommandTester($command);
}

it('seeds on a fresh install', function (): void {
    // Defensive: the per-test transaction is rolled back, but make sure no
    // admin user lingers from a previous run.
    Spora\Models\User::where('email', 'admin@spora.local')->delete();

    $tester = makeSetupTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('Running Spora database migrations')
        ->toContain('Schema is up to date')
        ->toContain('Fresh installation — running seeder...');

    $user = Spora\Models\User::where('email', 'admin@spora.local')->firstOrFail();
    expect($user->verified)->toBe(1)
        ->and($user->roles_mask)->toBe(Role::ADMIN)
        ->and($user->status)->toBe(1);
});

it('skips seeding on a second run when users and agents exist', function (): void {
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
        ->toContain('Existing installation detected. Skipping seeding.')
        ->toContain('db:repair-admin');
});
