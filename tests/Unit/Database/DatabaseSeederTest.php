<?php

declare(strict_types=1);

use Spora\Core\DatabaseSeeder;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\User;

it('seeds the admin user and agent successfully', function () {
    $authService = bootAuthLayer();
    $seeder = new DatabaseSeeder($authService);

    // Initial state
    expect(User::count())->toBe(0)
        ->and(Agent::count())->toBe(0)
        ->and(AgentTool::count())->toBe(0);

    // Run the seeder
    ob_start();
    $seeder->run();
    $output = ob_get_clean();

    expect($output)->toContain('Created Admin User')
        ->toContain('Created Default Agent')
        ->toContain('Enabled 3 Base Tools');

    // Assert databases state
    $user = User::where('email', 'admin@spora.local')->first();
    expect($user)->not->toBeNull();

    $agent = Agent::where('user_id', $user->id)->first();
    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Spora Core Agent');

    $tools = AgentTool::where('agent_id', $agent->id)->get();
    expect($tools)->toHaveCount(3);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('does not duplicate records if seeder is run twice', function () {
    $authService = bootAuthLayer();
    $seeder = new DatabaseSeeder($authService);

    // First run
    ob_start();
    $seeder->run();
    ob_get_clean();

    // Second run
    ob_start();
    $seeder->run();
    $output = ob_get_clean();

    expect($output)->toContain('Admin user already exists')
        ->toContain('Default Agent already exists')
        ->toContain('Enabled 3 Base Tools');

    // Assert counts haven't abnormally expanded
    expect(User::where('email', 'admin@spora.local')->count())->toBe(1)
        ->and(Agent::where('name', 'Spora Core Agent')->count())->toBe(1)
        ->and(AgentTool::count())->toBe(3);
})->afterEach(fn() => Spora\Core\Database::resetBootState());
