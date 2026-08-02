<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
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
        ->toContain("Created Spora Core Agent from 'core/core-assistant' template")
        ->toContain('2 tools');

    // Assert database state
    $user = User::where('email', 'admin@spora.local')->first();
    expect($user)->not->toBeNull();

    $agent = Agent::where('user_id', $user->id)->first();
    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Spora Core Agent');

    $tools = AgentTool::where('agent_id', $agent->id)->get();
    expect($tools)->toHaveCount(2);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('does not duplicate records if seeder is run twice', function () {
    $seeder = makeSeeder();

    ob_start();
    $seeder->run();
    ob_get_clean();

    ob_start();
    $seeder->run();
    $output = ob_get_clean();

    expect(User::count())->toBe(1);
    expect(Agent::count())->toBe(1);
    expect($output)->toContain('Spora Core Agent already exists');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('does not modify an existing admin row (security)', function () {
    // Operator-customised admin: renamed, no admin role, suspended. The seeder
    // must leave it untouched so it cannot re-grant admin via `db:seed`.
    $now = date('Y-m-d H:i:s');
    Capsule::table('users')->insert([
        'email'        => 'admin@spora.local',
        'password'     => password_hash('custom-password', PASSWORD_BCRYPT),
        'username'     => 'admin',
        'status'       => 0,
        'verified'     => 0,
        'resettable'   => 1,
        'roles_mask'   => 0,
        'registered'   => time(),
        'last_login'   => null,
        'force_logout' => 0,
        'name'         => 'Renamed Operator',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    $existingUserId = (int) Capsule::table('users')->where('email', 'admin@spora.local')->value('id');
    Agent::create([
        'user_id'   => $existingUserId,
        'name'      => 'Spora Core Agent',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $before = Capsule::table('users')->where('email', 'admin@spora.local')->first();

    ob_start();
    makeSeeder()->run();
    ob_get_clean();

    $after = Capsule::table('users')->where('email', 'admin@spora.local')->first();
    expect($after->name)->toBe($before->name)
        ->and((int) $after->verified)->toBe((int) $before->verified)
        ->and((int) $after->roles_mask)->toBe((int) $before->roles_mask)
        ->and((int) $after->status)->toBe((int) $before->status);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('does not recreate a deleted admin row (security)', function () {
    expect(User::where('email', 'admin@spora.local')->exists())->toBeFalse();

    ob_start();
    $output = ob_get_clean();

    expect(User::where('email', 'admin@spora.local')->exists())->toBeFalse()
        ->and($output)->not->toContain('Created Admin User');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
