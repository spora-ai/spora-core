<?php

declare(strict_types=1);

use Spora\Http\AuthController;
use Spora\Services\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    RateLimiter::resetAll();
});

afterEach(function (): void {
    RateLimiter::resetAll();
});

function makeAuthController(): array
{
    $authService = bootAuthLayer();
    $controller = new AuthController($authService, ['allow_registration' => true]);

    return [$controller, $authService];
}

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

test('login returns 429 after exceeding rate limit', function (): void {
    [$controller] = makeAuthController();

    // Register a user so login can be attempted
    $authService = bootAuthLayer();
    $authService->register('slowuser@example.com', 'Password1!');

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
    [$controller, $authService] = makeAuthController();
    $userId = $authService->register('headeruser@example.com', 'Password1!');

    $req = jsonRequest('POST', '/api/v1/auth/login', [
        'email' => 'headeruser@example.com',
        'password' => 'Password1!',
    ]);
    $response = $controller->login($req);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect($response->headers->get('X-RateLimit-Limit'))->toBe('5');
    expect($response->headers->get('X-RateLimit-Remaining'))->toBe('4');
});

test('login includes Retry-After header when rate limited', function (): void {
    [$controller] = makeAuthController();

    $authService = bootAuthLayer();
    $authService->register('retryuser@example.com', 'Password1!');

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
    [$controller] = makeAuthController();

    // Exhaust the rate limit (5 attempts)
    for ($i = 0; $i < 5; $i++) {
        $req = jsonRequest('POST', '/api/v1/auth/register', [
            'email' => "ratelimit{$i}@example.com",
            'password' => 'Password1!',
        ]);
        $controller->register($req);
    }

    // 6th attempt should be rate-limited
    $req = jsonRequest('POST', '/api/v1/auth/register', [
        'email' => 'ratelimit6@example.com',
        'password' => 'Password1!',
    ]);
    $response = $controller->register($req);

    expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('TOO_MANY_REQUESTS');
});

test('successful login clears rate limit bucket', function (): void {
    [$controller, $authService] = makeAuthController();
    $userId = $authService->register('clearuser@example.com', 'Password1!');

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
    [$controller, $authService] = makeAuthController();
    $authService->register('pwuser@example.com', 'OldPassword1!');

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
    [$controller] = makeAuthController();

    $req = jsonRequest('PATCH', '/api/v1/auth/password', [
        'current_password' => 'old',
        'new_password' => 'new',
    ]);
    $response = $controller->password($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

test('password endpoint validates required fields', function (): void {
    [$controller, $authService] = makeAuthController();
    $authService->register('pwvalidate@example.com', 'Password1!');

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
    [$controller, $authService] = makeAuthController();
    $authService->register('accuser@example.com', 'Password1!');

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
    [$controller] = makeAuthController();

    $req = jsonRequest('PATCH', '/api/v1/auth/account', [
        'username' => 'AnyName',
    ]);
    $response = $controller->account($req);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});
