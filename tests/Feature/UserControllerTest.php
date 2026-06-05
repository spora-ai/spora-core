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
if (! function_exists('makeAdmin')) {
    function makeAdmin(AuthService $authService, int $userId): void
    {
        $authService->grantRole($userId, Role::ADMIN);
    }
}

/**
 * Register a user with a display name. Returns the user ID.
 */
function makeUser(AuthService $authService, string $email, string $name = 'Test User'): int
{
    return $authService->register($email, 'Password1!', $name);
}

/**
 * Register a user with the default password and the email as the display name.
 * Convenient for tests that don't care about the display name.
 */
function regUser(AuthService $authService, string $email): int
{
    return $authService->register($email, 'Password1!', $email);
}

// Tests

test('updateUser can set name field', function (): void {
    [$controller, $authService] = makeUserController();

    $userId = regUser($authService, 'alice@example.com');
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

    $userId = regUser($authService, 'bob@example.com');
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

    $userId = regUser($authService, 'carol@example.com');
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

    $userId = regUser($authService, 'dave@example.com');
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

    $userId = regUser($authService, 'eve@example.com');
    makeAdmin($authService, $userId);
    simulateLoggedInSession($userId, 'eve@example.com');

    // Set name and verified
    $controller->update(jsonRequest('PATCH', "/api/v1/users/{$userId}", [
        'name'     => 'Eve Adams',
        'verified' => true,
    ]), $userId);

    // Fetch via getUser
    $response = $controller->show($userId);

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['user'])->toHaveKey('name');
    expect($body['data']['user'])->toHaveKey('verified');
    expect($body['data']['user']['name'])->toBe('Eve Adams');
    expect($body['data']['user']['verified'])->toBeTrue();
});

test('getUsers includes name and verified for each user', function (): void {
    [$controller, $authService] = makeUserController();

    $userId1 = regUser($authService, 'user1@example.com');
    $userId2 = regUser($authService, 'user2@example.com');
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

    $userId = regUser($authService, 'frank@example.com');
    makeAdmin($authService, $userId);
    simulateLoggedInSession($userId, 'frank@example.com');

    $request = jsonRequest('PATCH', '/api/v1/users/99999', [
        'name' => 'Ghost',
    ]);
    $response = $controller->update($request, 99999);

    expect($response->getStatusCode())->toBe(404);
});

// Admin user management

test('index() returns paginated list of users', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-index@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-index@example.com');

    $response = $controller->index(new Symfony\Component\HttpFoundation\Request());

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data'])->toBeArray();
    expect($body['meta'])->toHaveKey('total');
});

test('show() returns a user by id', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-show@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-show@example.com');

    $targetId = regUser($authService, 'target@example.com');

    $response = $controller->show($targetId);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['id'])->toBe($targetId);
});

test('show() returns 404 for unknown id', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-show404@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-show404@example.com');

    $response = $controller->show(999999);

    expect($response->getStatusCode())->toBe(404);
});

test('store() creates a new user', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-store@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-store@example.com');

    $request = jsonRequest('POST', '/api/v1/users', [
        'email'    => 'newuser@example.com',
        'password' => 'Password1!',
    ]);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['email'])->toBe('newuser@example.com');
});

test('store() returns 422 when required fields are missing', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-store422@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-store422@example.com');

    $request = jsonRequest('POST', '/api/v1/users', []);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(422);
});

test('store() returns 409 when email is taken', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-taken@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-taken@example.com');

    regUser($authService, 'existing@example.com');

    $request = jsonRequest('POST', '/api/v1/users', [
        'email'    => 'existing@example.com',
        'password' => 'Password1!',
    ]);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(409);
});

test('destroy() deletes a user', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-del@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-del@example.com');

    $targetId = regUser($authService, 'to-delete@example.com');

    $response = $controller->destroy($targetId);

    expect($response->getStatusCode())->toBe(200);
    expect(Spora\Models\User::find($targetId))->toBeNull();
});

test('destroy() returns 409 when admin tries to delete themselves', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-selfdel@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-selfdel@example.com');

    $response = $controller->destroy($adminId);

    expect($response->getStatusCode())->toBe(409);
});

test('destroy() returns 404 for unknown id', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-del404@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-del404@example.com');

    $response = $controller->destroy(999999);

    expect($response->getStatusCode())->toBe(404);
});

test('grantRole() assigns a role to a user', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-grant@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-grant@example.com');

    $targetId = regUser($authService, 'grantee@example.com');

    $request = jsonRequest('POST', "/api/v1/users/{$targetId}/roles", ['role' => 'ADMIN']);
    $response = $controller->grantRole($request, $targetId);

    expect($response->getStatusCode())->toBe(200);
    expect($authService->userHasRole($targetId, Role::ADMIN))->toBeTrue();
});

test('grantRole() returns 422 for invalid role', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-grantbad@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-grantbad@example.com');

    $targetId = regUser($authService, 'grantee2@example.com');

    $request = jsonRequest('POST', "/api/v1/users/{$targetId}/roles", ['role' => 'NOT_A_ROLE']);
    $response = $controller->grantRole($request, $targetId);

    expect($response->getStatusCode())->toBe(422);
});

test('revokeRole() removes a role from a user', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-revoke@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-revoke@example.com');

    $targetId = regUser($authService, 'revokee@example.com');
    $authService->grantRole($targetId, Role::ADMIN);

    $response = $controller->revokeRole($targetId, 'admin');

    expect($response->getStatusCode())->toBe(200);
    expect($authService->userHasRole($targetId, Role::ADMIN))->toBeFalse();
});

test('revokeRole() returns 422 for invalid role', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-revokebad@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-revokebad@example.com');

    $targetId = regUser($authService, 'revokee2@example.com');

    $response = $controller->revokeRole($targetId, 'NOT_A_ROLE');

    expect($response->getStatusCode())->toBe(422);
});

test('listRoles() returns a list of role names for a user', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-listroles@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-listroles@example.com');

    $targetId = regUser($authService, 'rolelist@example.com');
    $authService->grantRole($targetId, Role::AUTHOR);

    $response = $controller->listRoles($targetId);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['roles'])->toContain('AUTHOR');
});

test('listRoles() returns 404 for unknown id', function (): void {
    [$controller, $authService] = makeUserController();
    $adminId = regUser($authService, 'admin-listroles404@example.com');
    makeAdmin($authService, $adminId);
    simulateLoggedInSession($adminId, 'admin-listroles404@example.com');

    $response = $controller->listRoles(999999);

    expect($response->getStatusCode())->toBe(404);
});
