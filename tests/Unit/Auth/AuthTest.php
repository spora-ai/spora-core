<?php

declare(strict_types=1);

const REGISTER_URL = '/api/v1/auth/register';
const LOGIN_URL = '/api/v1/auth/login';
const ME_URL = '/api/v1/auth/me';
defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');
const ALICE_EMAIL = 'alice@example.com';
const EVE_EMAIL = 'eve@example.com';
const GRACE_EMAIL = 'grace@example.com';

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Http\AuthController;
use Spora\Services\RateLimiter;
use Spora\Services\UserServiceInterface;

beforeEach(function (): void {
    RateLimiter::resetAll();
});

afterEach(function (): void {
    RateLimiter::resetAll();
});

/**
 * Build an AuthController backed by a fresh database.
 * Pass custom config overrides (e.g. ['allow_registration' => false]).
 */
function makeAuthController(array $configOverrides = [], ?callable $userServiceSetup = null): array
{
    $service    = bootAuthLayer();
    $config     = array_merge(['allow_registration' => true, 'app_env' => 'testing'], $configOverrides);
    $userService = Mockery::mock(UserServiceInterface::class);
    $userService->allows('getUser')->andReturn(null)->byDefault();
    if ($userServiceSetup !== null) {
        $userServiceSetup($userService);
    }
    $csrfService = new Spora\Security\CsrfTokenService();
    $validator = new Spora\Services\AuthValidator();
    $workflow = new Spora\Services\AuthWorkflow($service, $userService, $csrfService, $validator);
    $controller = new AuthController($service, $csrfService, $validator, $workflow, $config);

    return [$controller, $service, $userService, $csrfService];
}

// Registration tests

test('register happy path returns 201 with user data and creates DB row', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $request  = jsonRequest('POST', REGISTER_URL, [
        'email'    => ALICE_EMAIL,
        'password' => TEST_PASSWORD,
        'confirm_password' => TEST_PASSWORD,
        'display_name' => 'Alice',
    ]);
    $response = $controller->register($request);

    expect($response->getStatusCode())->toBe(201);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('data');
    expect($body['data']['user'])->toHaveKey('id');
    expect($body['data']['user']['email'])->toBe(ALICE_EMAIL);

    // Verify the row is in the users table
    $userId = $body['data']['user']['id'];
    $row    = Capsule::table('users')->where('id', $userId)->first();
    expect($row)->not()->toBeNull();
    expect($row->email)->toBe(ALICE_EMAIL);
});

test('register duplicate email returns 409 EMAIL_TAKEN', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $payload = ['email' => 'bob@example.com', 'password' => TEST_PASSWORD, 'confirm_password' => TEST_PASSWORD, 'display_name' => 'Bob'];

    // First registration succeeds
    $controller->register(jsonRequest('POST', REGISTER_URL, $payload));

    // Second registration with same email should fail
    $response = $controller->register(jsonRequest('POST', REGISTER_URL, $payload));

    expect($response->getStatusCode())->toBe(409);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('EMAIL_TAKEN');
});

test('register with allow_registration=false returns 403 REGISTRATION_DISABLED', function (): void {
    clearSession();
    [$controller] = makeAuthController(['allow_registration' => false]);

    $response = $controller->register(jsonRequest('POST', REGISTER_URL, [
        'email'    => 'carol@example.com',
        'password' => TEST_PASSWORD,
        'display_name' => 'Carol',
    ]));

    expect($response->getStatusCode())->toBe(403);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('REGISTRATION_DISABLED');
});

test('register with missing email returns 422 VALIDATION_ERROR', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->register(jsonRequest('POST', REGISTER_URL, [
        'password' => TEST_PASSWORD,
        'confirm_password' => TEST_PASSWORD,
        'display_name' => 'Dave',
    ]));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

test('register with missing password returns 422 VALIDATION_ERROR', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->register(jsonRequest('POST', REGISTER_URL, [
        'email' => 'dave@example.com',
        'confirm_password' => TEST_PASSWORD,
        'display_name' => 'Dave',
    ]));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

test('register with missing display_name returns 422 VALIDATION_ERROR', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->register(jsonRequest('POST', REGISTER_URL, [
        'email' => EVE_EMAIL,
        'password' => TEST_PASSWORD,
        'confirm_password' => TEST_PASSWORD,
    ]));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

test('register with missing confirm_password returns 422 VALIDATION_ERROR', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->register(jsonRequest('POST', REGISTER_URL, [
        'email' => EVE_EMAIL,
        'password' => TEST_PASSWORD,
        'display_name' => 'Eve',
    ]));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

test('register with mismatched passwords returns 422 VALIDATION_ERROR', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->register(jsonRequest('POST', REGISTER_URL, [
        'email' => 'mismatch@example.com',
        'password' => TEST_PASSWORD,
        'confirm_password' => 'DifferentPass1!',
        'display_name' => 'Mismatch',
    ]));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toBe('Passwords do not match.');
});

// Login tests

test('login happy path returns 200 with user data', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    // Register the user first
    $service->register(EVE_EMAIL, TEST_PASSWORD, 'Eve');
    clearSession();

    $response = $controller->login(jsonRequest('POST', LOGIN_URL, [
        'email'    => EVE_EMAIL,
        'password' => TEST_PASSWORD,
    ]));

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('data');
    expect($body['data']['user']['email'])->toBe(EVE_EMAIL);
    expect($body['data']['user'])->toHaveKey('id');
    expect($body['data']['user'])->toHaveKey('username');
});

test('login with wrong password returns 401 INVALID_CREDENTIALS', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    $service->register('frank@example.com', TEST_PASSWORD, 'Frank');
    clearSession();

    $response = $controller->login(jsonRequest('POST', LOGIN_URL, [
        'email'    => 'frank@example.com',
        'password' => 'WrongPassword99!',
    ]));

    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_CREDENTIALS');
});

test('login with unknown email returns 401 INVALID_CREDENTIALS', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->login(jsonRequest('POST', LOGIN_URL, [
        'email'    => 'nobody@example.com',
        'password' => TEST_PASSWORD,
    ]));

    expect($response->getStatusCode())->toBe(401);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_CREDENTIALS');
});

// Me tests

test('me when logged in returns 200 with user data including ISO 8601 registered', function (): void {
    clearSession();
    [$controller, $service, $userService] = makeAuthController();

    $userId = $service->register(GRACE_EMAIL, TEST_PASSWORD, 'Grace');

    // Simulate session as if login() had been called
    simulateLoggedInSession($userId, GRACE_EMAIL);

    // Mock getUser to return the real user data
    $userService->expects('getUser')
        ->with($userId)
        ->andReturn([
            'user' => [
                'id'       => $userId,
                'email'    => GRACE_EMAIL,
                'name'     => 'Grace',
                'registered' => time(),
                'roles'    => [],
            ],
        ]);

    $response = $controller->me(jsonRequest('GET', ME_URL));

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['id'])->toBe($userId);
    expect($body['data']['user']['email'])->toBe(GRACE_EMAIL);
    expect($body['data']['user'])->toHaveKey('name');
    expect($body['data']['user'])->toHaveKey('registered');

    // registered must be a valid ISO 8601 date string
    $registered = $body['data']['user']['registered'];
    $dt = DateTime::createFromFormat(DateTime::ATOM, $registered);
    expect($dt)->not()->toBeFalse();
});

test('me when not logged in returns 401 UNAUTHENTICATED', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->me(jsonRequest('GET', ME_URL));

    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
});

// Logout tests

test('logout returns 204 with no body', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    $userId = $service->register('henry@example.com', TEST_PASSWORD, 'Henry');
    simulateLoggedInSession($userId, 'henry@example.com');

    $response = $controller->logout(jsonRequest('POST', '/api/v1/auth/logout'));

    expect($response->getStatusCode())->toBe(204);
    expect($response->getContent())->toBe('');
});

test('after logout me returns 401 UNAUTHENTICATED', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    $userId = $service->register('iris@example.com', TEST_PASSWORD, 'Iris');
    simulateLoggedInSession($userId, 'iris@example.com');

    // Log out
    $controller->logout(jsonRequest('POST', '/api/v1/auth/logout'));

    // Now me should return 401
    $response = $controller->me(jsonRequest('GET', ME_URL));

    expect($response->getStatusCode())->toBe(401);
});
