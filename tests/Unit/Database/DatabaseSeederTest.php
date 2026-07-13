<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Core\DatabaseSeeder;
use Spora\Core\Paths;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\User;
use Spora\Plugins\PluginLoader;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\ToolConfigService;

function makeSeeder(): DatabaseSeeder
{
    $authService = bootAuthLayer();
    $templateLoader = new EmailTemplateLoader(new Paths(BASE_PATH));

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

    return new DatabaseSeeder($authService, $templateLoader, $importer);
}

it('seeds the admin user and agent successfully', function () {
    // Initial state
    expect(User::count())->toBe(0)
        ->and(Agent::count())->toBe(0)
        ->and(AgentTool::count())->toBe(0);

    // Run the seeder
    ob_start();
    makeSeeder()->run();
    $output = ob_get_clean();

    expect($output)->toContain('Created Admin User')
        ->toContain("Created Spora Core Agent from 'core-assistant' template")
        ->toContain('4 tools');

    // Assert database state
    $user = User::where('email', 'admin@spora.local')->first();
    expect($user)->not->toBeNull();

    $agent = Agent::where('user_id', $user->id)->first();
    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Spora Core Agent');

    $tools = AgentTool::where('agent_id', $agent->id)->get();
    expect($tools)->toHaveCount(4);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('does not duplicate records if seeder is run twice', function () {
    $seeder = makeSeeder();

    // First run
    ob_start();
    $seeder->run();
    ob_get_clean();

    // Second run
    ob_start();
    $seeder->run();
    $output = ob_get_clean();

    expect(User::count())->toBe(1);
    expect(Agent::count())->toBe(1);
    expect($output)->toContain('Spora Core Agent already exists');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
