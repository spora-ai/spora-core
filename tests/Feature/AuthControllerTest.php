<?php

declare(strict_types=1);

use Spora\Http\AuthController;
use Spora\Services\RateLimiter;
use Spora\Services\UserServiceInterface;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    RateLimiter::resetAll();
});

afterEach(function (): void {
    RateLimiter::resetAll();
});

function makeAuthControllerWithMocks(): array
{
    clearSession();
    $authService = bootAuthLayer();
    $userService = Mockery::mock(UserServiceInterface::class);
    $userService->allows('getUser')->andReturn(null)->byDefault();
    $controller = new AuthController($authService, $userService, ['allow_registration' => true]);

    return [$controller, $authService];
}

function makeAuthControllerWithUserService(): array
{
    clearSession();
    $authService = bootAuthLayer();
    $userService = Mockery::mock(UserServiceInterface::class);
    $userService->allows('getUser')->andReturn(null)->byDefault();
    $userService->allows('updateUser')->andReturnUsing(function (int $userId, array $data): array {
        return ['user' => ['id' => $userId, 'username' => $data['username'] ?? null]];
    })->byDefault();
    $controller = new AuthController($authService, $userService, ['allow_registration' => true]);

    return [$controller, $authService, $userService];
}

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

test('login returns 429 after exceeding rate limit', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    // Register a user so login can be attempted
    $authService = bootAuthLayer();
    $authService->register('slowuser@example.com', 'Password1!', 'Slow User');

    // Exhaust the rate limit (5 attempts)
    for ($i = 0; $i < 5; $i++) {
        $req = jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'slowuser@example.com',
            'password' => 'wrongpassword',
        ]);
        $controller->login($req);
    }

    // 6th attempt should be rate-limited
    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'slowuser@example.com',
        'password' => 'wrongpassword',
    ]);
    $response = $controller->login($req);

    expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('TOO_MANY_REQUESTS');
});

test('login returns rate limit headers on success', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();
    $userId = $authService->register('headeruser@example.com', 'Password1!', 'Header User');

    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'headeruser@example.com',
        'password' => 'Password1!',
    ]);
    $response = $controller->login($req);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect($response->headers->get('X-RateLimit-Limit'))->toBe('5');
    expect($response->headers->get('X-RateLimit-Remaining'))->toBe('5');
});

test('login includes Retry-After header when rate limited', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    $authService = bootAuthLayer();
    $authService->register('retryuser@example.com', 'Password1!', 'Retry User');

    // Exhaust the rate limit
    for ($i = 0; $i < 5; $i++) {
        $req = jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'retryuser@example.com',
            'password' => 'wrong',
        ]);
        $controller->login($req);
    }

    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'retryuser@example.com',
        'password' => 'wrong',
    ]);
    $response = $controller->login($req);

    expect($response->headers->has('Retry-After'))->toBeTrue();
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('register returns 429 after exceeding rate limit', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    // Exhaust the rate limit (5 attempts)
    for ($i = 0; $i < 5; $i++) {
        $req = jsonRequest('POST', '/api/v1/auth/register', [
            'email' => "ratelimit{$i}@example.com",
            'password' => 'Password1!',
            'display_name' => "Ratelimit{$i}",
        ]);
        $controller->register($req);
    }

    // 6th attempt should be rate-limited
    $req = jsonRequest('POST', '/api/v1/auth/register', [
        'email' => 'ratelimit6@example.com',
        'password' => 'Password1!',
        'display_name' => 'Ratelimit6',
    ]);
    $response = $controller->register($req);

    expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('TOO_MANY_REQUESTS');
});

test('successful login clears rate limit bucket', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();
    $userId = $authService->register('clearuser@example.com', 'Password1!', 'Clear User');

    // Make 3 failed attempts
    for ($i = 0; $i < 3; $i++) {
        $req = jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'clearuser@example.com',
            'password' => 'wrong',
        ]);
        $controller->login($req);
    }

    // Successful login should clear the bucket
    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'clearuser@example.com',
        'password' => 'Password1!',
    ]);
    $response = $controller->login($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    // Another 3 failed attempts should not trigger rate limit
    for ($i = 0; $i < 3; $i++) {
        $req = jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'clearuser@example.com',
            'password' => 'wrong',
        ]);
        $response = $controller->login($req);
        expect($response->getStatusCode())->not()->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    }
});

test('password endpoint changes password', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();
    $authService->register('pwuser@example.com', 'OldPassword1!', 'Pw User');

    // Login first
    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'pwuser@example.com',
        'password' => 'OldPassword1!',
    ]);
    $controller->login($req);

    // Change password
    $req = jsonRequest('PATCH', '/api/v1/auth/password', [
        'current_password' => 'OldPassword1!',
        'new_password' => 'NewPassword1!',
    ]);
    $response = $controller->password($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['message'])->toBe('Password updated');

    // Login with new password should work
    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'pwuser@example.com',
        'password' => 'NewPassword1!',
    ]);
    $response = $controller->login($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
});

test('password endpoint requires authentication', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    $req = jsonRequest('PATCH', '/api/v1/auth/password', [
        'current_password' => 'old',
        'new_password' => 'new',
    ]);
    $response = $controller->password($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

test('password endpoint validates required fields', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();
    $authService->register('pwvalidate@example.com', 'Password1!', 'Pw Validate');

    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'pwvalidate@example.com',
        'password' => 'Password1!',
    ]);
    $controller->login($req);

    $req = jsonRequest('PATCH', '/api/v1/auth/password', [
        'current_password' => 'old',
        // missing new_password
    ]);
    $response = $controller->password($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('account endpoint updates username', function (): void {
    [$controller, $authService] = makeAuthControllerWithUserService();
    $authService->register('accuser@example.com', 'Password1!', 'Acc User');

    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'accuser@example.com',
        'password' => 'Password1!',
    ]);
    $controller->login($req);

    $req = jsonRequest('PATCH', '/api/v1/auth/account', [
        'username' => 'Neuer Name',
    ]);
    $response = $controller->account($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['user']['username'])->toBe('Neuer Name');
});

test('account endpoint requires authentication', function (): void {
    [$controller] = makeAuthControllerWithUserService();

    $req = jsonRequest('PATCH', '/api/v1/auth/account', [
        'username' => 'AnyName',
    ]);
    $response = $controller->account($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

// ---------------------------------------------------------------------------
// Password reset
// ---------------------------------------------------------------------------

test('resetPassword resets password with valid selector and token', function (): void {
    [$controller, $authService] = makeAuthControllerWithMocks();

    $email = 'resetuser@example.com';
    $oldPassword = 'OldPassword1!';
    $newPassword = 'NewPassword1!';
    $authService->register($email, $oldPassword, 'Reset User');

    // Initiate password reset to generate selector/token
    $authService->forgotPassword($email);

    // Get the selector and token from the database
    $pdo = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $stmt = $pdo->prepare("SELECT reset_selector, reset_token FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    $selector = $row['reset_selector'];
    $token = $row['reset_token'];

    // Reset the password
    $req = jsonRequest('POST', '/api/v1/auth/reset-password', [
        'selector' => $selector,
        'token' => $token,
        'password' => $newPassword,
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['message'])->toBe('Password reset successfully.');

    // Login with new password should work
    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => $email,
        'password' => $newPassword,
    ]);
    $response = $controller->login($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
});

test('resetPassword returns error for invalid selector', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    $req = jsonRequest('POST', '/api/v1/auth/reset-password', [
        'selector' => 'invalid_selector',
        'token' => 'invalid_token',
        'password' => 'NewPassword1!',
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_TOKEN');
});

test('resetPassword validates required fields', function (): void {
    [$controller] = makeAuthControllerWithMocks();

    // Missing selector
    $req = jsonRequest('POST', '/api/v1/auth/reset-password', [
        'token' => 'some_token',
        'password' => 'NewPassword1!',
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Missing token
    $req = jsonRequest('POST', '/api/v1/auth/reset-password', [
        'selector' => 'some_selector',
        'password' => 'NewPassword1!',
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Missing password
    $req = jsonRequest('POST', '/api/v1/auth/reset-password', [
        'selector' => 'some_selector',
        'token' => 'some_token',
    ]);
    $response = $controller->resetPassword($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});
