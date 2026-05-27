<?php

declare(strict_types=1);

use Spora\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

// ---------------------------------------------------------------------------
// Config endpoint
// ---------------------------------------------------------------------------

test('GET /api/v1/config returns allow_registration', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/config', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('allow_registration');
    expect(is_bool($body['allow_registration']))->toBeTrue();
});

// ---------------------------------------------------------------------------
// GET /api/v1/auth/verify/{selector} — validation errors
// ---------------------------------------------------------------------------

test('GET /api/v1/auth/verify/{selector} without token returns 422', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/auth/verify/test-selector', 'GET');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

// ---------------------------------------------------------------------------
// POST /api/v1/auth/forgot-password — validation errors
// ---------------------------------------------------------------------------

test('POST /api/v1/auth/forgot-password without email returns 422', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/auth/forgot-password', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['password' => 'test']));
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

// ---------------------------------------------------------------------------
// POST /api/v1/auth/verification/resend — validation errors
// ---------------------------------------------------------------------------

test('POST /api/v1/auth/verification/resend without email returns 422', function (): void {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/auth/verification/resend', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ], json_encode([]));
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

// ---------------------------------------------------------------------------
// POST /api/v1/auth/reset-password — validation errors
// ---------------------------------------------------------------------------

test('POST /api/v1/auth/reset-password without required fields returns 422', function (): void {
    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/auth/reset-password', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['email' => 'test@example.com']));
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

// ---------------------------------------------------------------------------
// POST /api/v1/auth/email/change-request — requires authentication
// ---------------------------------------------------------------------------

test('POST /api/v1/auth/email/change-request without auth returns 401', function (): void {
    clearSession();

    // Provide a valid CSRF token so CsrfMiddleware passes and AuthMiddleware runs
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/auth/email/change-request', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ], json_encode(['email' => 'new@example.com']));
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
});

// ---------------------------------------------------------------------------
// POST /api/v1/auth/email/change-request — validation errors (logged in)
// ---------------------------------------------------------------------------

test('POST /api/v1/auth/email/change-request without email returns 422 when authenticated', function (): void {
    $service = bootAuthLayer();
    $email   = 'change-email-test@example.com';
    $userId  = $service->register($email, 'Password1!', 'Change Email User');
    simulateLoggedInSession($userId, $email);

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    $kernel   = new Kernel();
    $request  = Request::create('/api/v1/auth/email/change-request', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CSRF_TOKEN' => $csrfToken,
    ], json_encode([]));
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});
