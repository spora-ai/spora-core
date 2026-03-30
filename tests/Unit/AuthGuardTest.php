<?php

declare(strict_types=1);

use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\Middleware\AuthGuard;

test('requireAuth throws UnauthenticatedException when not logged in', function (): void {
    clearSession();
    $authService = bootAuthLayer();

    expect(fn () => AuthGuard::requireAuth($authService))
        ->toThrow(UnauthenticatedException::class, 'Authentication required.');
});

test('requireAuth returns the user ID when logged in', function (): void {
    clearSession();
    $authService = bootAuthLayer();

    $userId = $authService->register('guard@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'guard@example.com');

    expect(AuthGuard::requireAuth($authService))->toBe($userId);
});
