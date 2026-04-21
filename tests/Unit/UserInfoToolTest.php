<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Spora\Tools\UserInfoTool;
use Spora\Services\ToolConfigService;
use Spora\Core\SecurityManager;

describe('UserInfoTool', function (): void {

    it('returns empty strings for null fields', function (): void {
        $authService = bootAuthLayer();
        $userId = bootAuth($authService, 'userinfo@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'userinfo@example.com');

        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());

        $result = $tool->execute(['action' => 'get_base_data'], agentId: 1);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Name: (not set)');
        expect($result->content)->toContain('Date of Birth: (not set)');
        expect($result->content)->toContain('About Me: (not set)');
    });

    it('returns filled fields when user has profile data', function (): void {
        $authService = bootAuthLayer();
        $userId = bootAuth($authService, 'userinfo2@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'userinfo2@example.com');

        $user = Spora\Models\User::find($userId);
        $user->name = 'Alice';
        $user->date_of_birth = '1990-05-15';
        $user->about_me = 'Hello world';
        $user->save();

        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());
        $result = $tool->execute(['action' => 'get_base_data'], agentId: 1);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Name: Alice');
        expect($result->content)->toContain('Date of Birth: 1990-05-15');
        expect($result->content)->toContain('About Me: Hello world');
    });

    it('returns error when not authenticated', function (): void {
        clearSession();
        $authService = bootAuthLayer();

        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());
        $result = $tool->execute(['action' => 'get_base_data'], agentId: 1);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('Not authenticated');
    });

    it('get_locations returns no locations when none exist', function (): void {
        $authService = bootAuthLayer();
        $userId = bootAuth($authService, 'userinfo3@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'userinfo3@example.com');

        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());
        $result = $tool->execute(['action' => 'get_locations'], agentId: 1);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('No locations saved');
    });

    it('get_locations returns user locations', function (): void {
        $authService = bootAuthLayer();
        $userId = bootAuth($authService, 'userinfo4@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'userinfo4@example.com');

        Spora\Models\UserLocation::create([
            'user_id' => $userId,
            'name'   => 'Home',
            'address' => '123 Main St',
            'is_default' => true,
        ]);

        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());
        $result = $tool->execute(['action' => 'get_locations'], agentId: 1);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Home');
        expect($result->content)->toContain('123 Main St');
        expect($result->content)->toContain('(default)');
    });

    it('get_health_data returns error when health data is not enabled', function (): void {
        $authService = bootAuthLayer();
        $userId = bootAuth($authService, 'userinfo5@example.com', 'Password1!');
        simulateLoggedInSession($userId, 'userinfo5@example.com');

        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());
        $result = $tool->execute(['action' => 'get_health_data'], agentId: 1);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('Health data access is not enabled');
    });

    it('get_health_data returns error when not authenticated', function (): void {
        clearSession();
        $authService = bootAuthLayer();

        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());
        $result = $tool->execute(['action' => 'get_health_data'], agentId: 1);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('Not authenticated');
    });

    it('describeAction returns correct descriptions', function (): void {
        $authService = bootAuthLayer();
        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());

        expect($tool->describeAction(['action' => 'get_base_data']))->toContain('base profile data');
        expect($tool->describeAction(['action' => 'get_locations']))->toContain('saved locations');
        expect($tool->describeAction(['action' => 'get_health_data']))->toContain('health measurements');
        expect($tool->describeAction(['action' => 'unknown']))->toContain('user information');
    });

    it('getParametersSchema returns valid schema', function (): void {
        $authService = bootAuthLayer();
        $tool = new UserInfoTool($authService, makeUserInfoToolConfigService());
        $schema = $tool->getParametersSchema();

        expect($schema['type'])->toBe('object');
        expect($schema['properties']['action']['enum'])
            ->toContain('get_base_data')
            ->toContain('get_locations')
            ->toContain('get_health_data');
        expect($schema['required'])->toContain('action');
    });
});

function makeUserInfoToolConfigService(): ToolConfigService
{
    $toolClasses = [
        Spora\Tools\CurrentTimeTool::class,
        Spora\Tools\CalculatorTool::class,
        Spora\Tools\UserInfoTool::class,
    ];
    $securityManager = new SecurityManager(random_bytes(32));

    return new ToolConfigService($securityManager, $toolClasses);
}
