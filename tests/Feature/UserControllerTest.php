<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Spora\Auth\AuthService;
use Spora\Http\UserController;
use Spora\Services\UserService;

/**
 * Build a UserController with real services backed by the test database.
 */
function makeUserController(): array
{
    $authService = bootAuthLayer();
    $userService = new UserService();
    $controller  = new UserController($authService, $userService);

    return [$controller, $authService, $userService];
}

/**
 * Grant admin role to a user.
 */
function makeAdmin(AuthService $authService, int $userId): void
{
    $authService->grantRole($userId, Role::ADMIN);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('updateUser can set name field', function (): void {
    [$controller, $authService] = makeUserController();

    $userId = $authService->register('alice@example.com', 'Password1!');
    makeAdmin($authService, $userId);
    simulateLoggedInSession($userId, 'alice@example.com');

    $request = jsonRequest('PATCH', "/api/v1/users/{$userId}", [
        'name' => 'Alice Smith',
    ]);
    $response = $controller->update($request, $userId);

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['name'])->toBe('Alice Smith');
});

test('updateUser can clear name field with empty string', function (): void {
    [$controller, $authService] = makeUserController();

    $userId = $authService->register('bob@example.com', 'Password1!');
    makeAdmin($authService, $userId);
    simulateLoggedInSession($userId, 'bob@example.com');

    // First set a name
    $controller->update(jsonRequest('PATCH', "/api/v1/users/{$userId}", ['name' => 'Bob']), $userId);

    // Then clear it
    $request = jsonRequest('PATCH', "/api/v1/users/{$userId}", ['name' => '']);
    $response = $controller->update($request, $userId);

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['name'])->toBeNull();
});

test('updateUser can set verified field to true', function (): void {
    [$controller, $authService] = makeUserController();

    $userId = $authService->register('carol@example.com', 'Password1!');
    makeAdmin($authService, $userId);
    simulateLoggedInSession($userId, 'carol@example.com');

    $request = jsonRequest('PATCH', "/api/v1/users/{$userId}", [
        'verified' => true,
    ]);
    $response = $controller->update($request, $userId);

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['verified'])->toBeTrue();
});

test('updateUser can set verified field to false', function (): void {
    [$controller, $authService] = makeUserController();

    $userId = $authService->register('dave@example.com', 'Password1!');
    makeAdmin($authService, $userId);
    simulateLoggedInSession($userId, 'dave@example.com');

    // First verify the user
    $controller->update(jsonRequest('PATCH', "/api/v1/users/{$userId}", ['verified' => true]), $userId);

    // Then unverify
    $request = jsonRequest('PATCH', "/api/v1/users/{$userId}", [
        'verified' => false,
    ]);
    $response = $controller->update($request, $userId);

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['verified'])->toBeFalse();
});

test('serializeUser includes name and verified fields', function (): void {
    [$controller, $authService] = makeUserController();

    $userId = $authService->register('eve@example.com', 'Password1!');
    makeAdmin($authService, $userId);
    simulateLoggedInSession($userId, 'eve@example.com');

    // Set name and verified
    $controller->update(jsonRequest('PATCH', "/api/v1/users/{$userId}", [
        'name'     => 'Eve Adams',
        'verified' => true,
    ]), $userId);

    // Fetch via getUser
    $response = $controller->show(jsonRequest('GET', "/api/v1/users/{$userId}"), $userId);

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['user'])->toHaveKey('name');
    expect($body['data']['user'])->toHaveKey('verified');
    expect($body['data']['user']['name'])->toBe('Eve Adams');
    expect($body['data']['user']['verified'])->toBeTrue();
});

test('getUsers includes name and verified for each user', function (): void {
    [$controller, $authService] = makeUserController();

    $userId1 = $authService->register('user1@example.com', 'Password1!');
    $userId2 = $authService->register('user2@example.com', 'Password1!');
    makeAdmin($authService, $userId1);
    simulateLoggedInSession($userId1, 'user1@example.com');

    // Set different name and verified states
    $controller->update(jsonRequest('PATCH', "/api/v1/users/{$userId1}", [
        'name'     => 'User One',
        'verified' => true,
    ]), $userId1);

    $controller->update(jsonRequest('PATCH', "/api/v1/users/{$userId2}", [
        'name'     => 'User Two',
        'verified' => false,
    ]), $userId2);

    // Fetch paginated list
    $response = $controller->index(jsonRequest('GET', '/api/v1/users'));

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data'])->toBeArray();

    // Find our users in the list
    $usersById = array_column($body['data'], null, 'id');

    expect($usersById[$userId1])->toHaveKey('name');
    expect($usersById[$userId1])->toHaveKey('verified');
    expect($usersById[$userId1]['name'])->toBe('User One');
    expect($usersById[$userId1]['verified'])->toBeTrue();

    expect($usersById[$userId2])->toHaveKey('name');
    expect($usersById[$userId2])->toHaveKey('verified');
    expect($usersById[$userId2]['name'])->toBe('User Two');
    expect($usersById[$userId2]['verified'])->toBeFalse();
});

test('updateUser returns 404 for non-existent user', function (): void {
    [$controller, $authService] = makeUserController();

    $userId = $authService->register('frank@example.com', 'Password1!');
    makeAdmin($authService, $userId);
    simulateLoggedInSession($userId, 'frank@example.com');

    $request = jsonRequest('PATCH', '/api/v1/users/99999', [
        'name' => 'Ghost',
    ]);
    $response = $controller->update($request, 99999);

    expect($response->getStatusCode())->toBe(404);
});
