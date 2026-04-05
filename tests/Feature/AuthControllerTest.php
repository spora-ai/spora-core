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
