<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Auth\AuthService;
use Spora\Console\Commands\SeedCommand;
use Spora\Core\Database;
use Spora\Core\Paths;
use Spora\Plugins\PluginLoader;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\ToolConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function makeSeedTester(?Closure $authFactoryOverride = null): CommandTester
{
    // The connection is already booted by tests/Pest.php beforeEach; do NOT
    // resetBootState() here, that would create a fresh in-memory SQLite that
    // the factory closure's Auth can't see.
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly(); // no-op: already booted

    $templateLoader = new EmailTemplateLoader(new Paths(BASE_PATH));
    $authFactory = $authFactoryOverride ?? static fn(): AuthService => bootAuthLayer();

    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    $toolConfig = new ToolConfigService($security, $logger, [
        Spora\Tools\CurrentTimeTool::class,
        Spora\Tools\CalculatorTool::class,
        Spora\Tools\AgentMemoryTool::class,
        Spora\Tools\GlobalMemoryTool::class,
        Spora\Tools\ReadUrlTool::class,
        Spora\Tools\UserInfoTool::class,
        Spora\Tools\HandoverTool::class,
    ]);
    $importer = new AgentTemplateImporter(
        $toolConfig,
        new PluginLoader([]),
        new Paths(BASE_PATH),
    );

    $command = new SeedCommand($db, $authFactory, $templateLoader, $importer, new Paths(BASE_PATH));
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

it('generates a fresh storage/secret.key on first run', function (): void {
    Spora\Models\User::where('email', 'admin@spora.local')->delete();

    $keyFile = BASE_PATH . '/storage/secret.key';
    $existed = is_file($keyFile);
    $existedContents = $existed ? file_get_contents($keyFile) : null;

    if ($existed) {
        @unlink($keyFile);
    }

    try {
        $tester = makeSeedTester();
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('Generated new secret key at');
        expect(is_file($keyFile))->toBeTrue();
        expect(filesize($keyFile))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    } finally {
        if (! $existed) {
            @unlink($keyFile);
        } else {
            file_put_contents($keyFile, $existedContents);
        }
    }
});

it('reports failure and exits with FAILURE when the factory throws', function (): void {
    // The factory closure is invoked AFTER bootDatabaseConnectionOnly, so a
    // throwing factory exercises the catch (Throwable) branch in SeedCommand::execute.
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    $templateLoader = new EmailTemplateLoader(new Paths(BASE_PATH));
    $authFactory = static function (): AuthService {
        throw new RuntimeException('factory exploded');
    };

    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    $toolConfig = new ToolConfigService($security, $logger, []);
    $importer = new AgentTemplateImporter(
        $toolConfig,
        new PluginLoader([]),
        new Paths(BASE_PATH),
    );

    $command = new SeedCommand($db, $authFactory, $templateLoader, $importer, new Paths(BASE_PATH));
    $command->setName('db:seed');
    $tester = new CommandTester($command);

    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::FAILURE);
    expect($tester->getDisplay())
        ->toContain('Seeding failed: factory exploded');
});
