<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\Database;
use Spora\Http\AuthController;
use Symfony\Component\HttpFoundation\Request;

// ---------------------------------------------------------------------------
// Bootstrap helpers
// ---------------------------------------------------------------------------

/**
 * Boot a fresh in-memory SQLite database and return a ready-to-use AuthService.
 * Throttling is disabled so tests never hit rate limits.
 */
function bootAuthLayer(): AuthService
{
    Database::resetBootState();

    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();

    $pdo  = Capsule::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false /* throttling off */);

    return new AuthService($auth);
}

/**
 * Build an AuthController backed by a fresh database.
 * Pass custom config overrides (e.g. ['allow_registration' => false]).
 */
function makeAuthController(array $configOverrides = []): array
{
    $service = bootAuthLayer();
    $config  = array_merge(['allow_registration' => true, 'app_env' => 'testing'], $configOverrides);
    $controller = new AuthController($service, $config);

    return [$controller, $service];
}

/**
 * Create a JSON POST/GET Request with an optional body array.
 */
function jsonRequest(string $method, string $uri, array $body = []): Request
{
    $content = $body !== [] ? json_encode($body) : '';

    return Request::create(
        $uri,
        strtoupper($method),
        [],       // parameters
        [],       // cookies
        [],       // files
        ['CONTENT_TYPE' => 'application/json'],
        $content,
    );
}

/**
 * Simulate a logged-in session by populating the PHP session superglobal
 * the same way delight-im/auth does internally.
 */
function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    // These are the session field names used by delight-im/auth (constants on Auth class).
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_LOGGED_IN]  = true;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID]    = $userId;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_EMAIL]      = $email;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USERNAME]   = null;
}

/**
 * Clear the session, simulating a not-logged-in state.
 */
function clearSession(): void
{
    $_SESSION = [];
}

// ---------------------------------------------------------------------------
// Registration tests
// ---------------------------------------------------------------------------

test('register happy path returns 201 with user data and creates DB row', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $request  = jsonRequest('POST', '/api/v1/auth/register', [
        'email'    => 'alice@example.com',
        'password' => 'Password1!',
    ]);
    $response = $controller->register($request);

    expect($response->getStatusCode())->toBe(201);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('data');
    expect($body['data']['user'])->toHaveKey('id');
    expect($body['data']['user']['email'])->toBe('alice@example.com');

    // Verify the row is in the users table
    $userId = $body['data']['user']['id'];
    $row    = Capsule::table('users')->where('id', $userId)->first();
    expect($row)->not()->toBeNull();
    expect($row->email)->toBe('alice@example.com');
});

test('register duplicate email returns 409 EMAIL_TAKEN', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $payload = ['email' => 'bob@example.com', 'password' => 'Password1!'];

    // First registration succeeds
    $controller->register(jsonRequest('POST', '/api/v1/auth/register', $payload));

    // Second registration with same email should fail
    $response = $controller->register(jsonRequest('POST', '/api/v1/auth/register', $payload));

    expect($response->getStatusCode())->toBe(409);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('EMAIL_TAKEN');
});

test('register with allow_registration=false returns 403 REGISTRATION_DISABLED', function (): void {
    clearSession();
    [$controller] = makeAuthController(['allow_registration' => false]);

    $response = $controller->register(jsonRequest('POST', '/api/v1/auth/register', [
        'email'    => 'carol@example.com',
        'password' => 'Password1!',
    ]));

    expect($response->getStatusCode())->toBe(403);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('REGISTRATION_DISABLED');
});

test('register with missing email returns 422 VALIDATION_ERROR', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->register(jsonRequest('POST', '/api/v1/auth/register', [
        'password' => 'Password1!',
    ]));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

test('register with missing password returns 422 VALIDATION_ERROR', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->register(jsonRequest('POST', '/api/v1/auth/register', [
        'email' => 'dave@example.com',
    ]));

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

// ---------------------------------------------------------------------------
// Login tests
// ---------------------------------------------------------------------------

test('login happy path returns 200 with user data', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    // Register the user first
    $service->register('eve@example.com', 'Password1!');
    clearSession();

    $response = $controller->login(jsonRequest('POST', '/api/v1/auth/login', [
        'email'    => 'eve@example.com',
        'password' => 'Password1!',
    ]));

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('data');
    expect($body['data']['user']['email'])->toBe('eve@example.com');
    expect($body['data']['user'])->toHaveKey('id');
    expect($body['data']['user'])->toHaveKey('username');
});

test('login with wrong password returns 401 INVALID_CREDENTIALS', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    $service->register('frank@example.com', 'Password1!');
    clearSession();

    $response = $controller->login(jsonRequest('POST', '/api/v1/auth/login', [
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

    $response = $controller->login(jsonRequest('POST', '/api/v1/auth/login', [
        'email'    => 'nobody@example.com',
        'password' => 'Password1!',
    ]));

    expect($response->getStatusCode())->toBe(401);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_CREDENTIALS');
});

// ---------------------------------------------------------------------------
// Me tests
// ---------------------------------------------------------------------------

test('me when logged in returns 200 with user data including ISO 8601 registered', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    $userId = $service->register('grace@example.com', 'Password1!');

    // Simulate session as if login() had been called
    simulateLoggedInSession($userId, 'grace@example.com');

    $response = $controller->me(jsonRequest('GET', '/api/v1/auth/me'));

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['id'])->toBe($userId);
    expect($body['data']['user']['email'])->toBe('grace@example.com');
    expect($body['data']['user'])->toHaveKey('username');
    expect($body['data']['user'])->toHaveKey('registered');

    // registered must be a valid ISO 8601 date string
    $registered = $body['data']['user']['registered'];
    $dt = DateTime::createFromFormat(DateTime::ATOM, $registered);
    expect($dt)->not()->toBeFalse();
});

test('me when not logged in returns 401 UNAUTHENTICATED', function (): void {
    clearSession();
    [$controller] = makeAuthController();

    $response = $controller->me(jsonRequest('GET', '/api/v1/auth/me'));

    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
});

// ---------------------------------------------------------------------------
// Logout tests
// ---------------------------------------------------------------------------

test('logout returns 204 with no body', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    $userId = $service->register('henry@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'henry@example.com');

    $response = $controller->logout(jsonRequest('POST', '/api/v1/auth/logout'));

    expect($response->getStatusCode())->toBe(204);
    expect($response->getContent())->toBe('');
});

test('after logout me returns 401 UNAUTHENTICATED', function (): void {
    clearSession();
    [$controller, $service] = makeAuthController();

    $userId = $service->register('iris@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'iris@example.com');

    // Log out
    $controller->logout(jsonRequest('POST', '/api/v1/auth/logout'));

    // Now me should return 401
    $response = $controller->me(jsonRequest('GET', '/api/v1/auth/me'));

    expect($response->getStatusCode())->toBe(401);
});
