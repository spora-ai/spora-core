<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Spora\Http\Middleware\AdminMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

test('handle() returns 401 JSON when no user is logged in', function (): void {
    $authService = bootAuthLayer();
    clearSession();
    $middleware = new AdminMiddleware($authService);

    $response = $middleware->handle(new Request(), fn() => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
});

test('handle() returns 403 JSON when user is not an admin', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('non-admin@example.com', 'Password1!', 'NonAdmin');
    simulateLoggedInSession($userId, 'non-admin@example.com');
    $middleware = new AdminMiddleware($authService);

    $response = $middleware->handle(new Request(), fn() => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('FORBIDDEN');
});

test('handle() calls $next() and returns its response when user is admin', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('admin-handle@example.com', 'Password1!', 'Admin');
    $authService->grantRole($userId, Role::ADMIN);
    simulateLoggedInSession($userId, 'admin-handle@example.com');
    $middleware = new AdminMiddleware($authService);

    $called = false;
    $response = $middleware->handle(new Request(), function () use (&$called): JsonResponse {
        $called = true;
        return new JsonResponse(['ok' => true], 201);
    });

    expect($called)->toBeTrue();
    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    expect($body['ok'])->toBeTrue();
});

test('handle() returns 403 JSON when user record was deleted', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('ghost-mw@example.com', 'Password1!', 'Ghost');
    simulateLoggedInSession($userId, 'ghost-mw@example.com');
    Spora\Models\User::where('id', $userId)->delete();
    $middleware = new AdminMiddleware($authService);

    $response = $middleware->handle(new Request(), fn() => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
});
