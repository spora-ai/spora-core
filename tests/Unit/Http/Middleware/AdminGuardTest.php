<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Spora\Http\Exceptions\ForbiddenException;
use Spora\Http\Middleware\AdminGuard;
use Spora\Models\User;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

test('requireAdmin() throws when no user is logged in', function (): void {
    $authService = bootAuthLayer();
    clearSession();

    expect(fn() => AdminGuard::requireAdmin($authService))
        ->toThrow(ForbiddenException::class, 'Authentication required.');
});

test('requireAdmin() throws when logged-in user is not an admin', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('regular@example.com', 'Password1!', 'Regular');
    simulateLoggedInSession($userId, 'regular@example.com');

    expect(fn() => AdminGuard::requireAdmin($authService))
        ->toThrow(ForbiddenException::class, 'Admin access required.');
});

test('requireAdmin() returns user id when user is admin', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('admin@example.com', 'Password1!', 'Admin');
    $authService->grantRole($userId, Role::ADMIN);
    simulateLoggedInSession($userId, 'admin@example.com');

    $result = AdminGuard::requireAdmin($authService);

    expect($result)->toBe($userId);
});

test('requireAdmin() throws when session user id is set but the user record is missing', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('ghost@example.com', 'Password1!', 'Ghost');
    simulateLoggedInSession($userId, 'ghost@example.com');

    // Delete the user record but keep the session
    User::where('id', $userId)->delete();

    expect(fn() => AdminGuard::requireAdmin($authService))
        ->toThrow(ForbiddenException::class, 'Admin access required.');
});
