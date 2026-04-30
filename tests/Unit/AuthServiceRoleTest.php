<?php

declare(strict_types=1);

namespace Tests\Unit;

use Delight\Auth\Role;
use Spora\Models\User;

/**
 * Role management tests.
 *
 * These require a real DB connection since delight-im Auth needs PDO
 * to manipulate the roles_mask column. The bootAuthLayer() helper
 * (defined in tests/Pest.php) provides an in-memory SQLite DB.
 */
test('grantRole adds role to roles_mask', function () {
    $authService = bootAuthLayer();

    $userId = $authService->register('role-test@example.com', 'Password1!');
    $user = User::find($userId);

    // User should not have ADMIN role initially
    expect($user->hasRole(Role::ADMIN))->toBeFalse();

    // Grant ADMIN role
    $authService->grantRole($userId, Role::ADMIN);

    // Refresh user from DB
    $user = User::find($userId);
    expect($user->hasRole(Role::ADMIN))->toBeTrue();
});

test('revokeRole removes role from roles_mask', function () {
    $authService = bootAuthLayer();

    $userId = $authService->register('revoke-test@example.com', 'Password1!');
    $user = User::find($userId);

    // Grant ADMIN role
    $authService->grantRole($userId, Role::ADMIN);

    // Refresh and verify role is set
    $user = User::find($userId);
    expect($user->hasRole(Role::ADMIN))->toBeTrue();

    // Revoke ADMIN role
    $authService->revokeRole($userId, Role::ADMIN);

    // Refresh and verify role is removed
    $user = User::find($userId);
    expect($user->hasRole(Role::ADMIN))->toBeFalse();
});

test('userHasRole returns correct boolean', function () {
    $authService = bootAuthLayer();

    $userId = $authService->register('hasrole-test@example.com', 'Password1!');

    // Initially no roles
    expect($authService->userHasRole($userId, Role::ADMIN))->toBeFalse();
    expect($authService->userHasRole($userId, Role::AUTHOR))->toBeFalse();

    // Grant ADMIN
    $authService->grantRole($userId, Role::ADMIN);
    expect($authService->userHasRole($userId, Role::ADMIN))->toBeTrue();
    expect($authService->userHasRole($userId, Role::AUTHOR))->toBeFalse();

    // Grant AUTHOR
    $authService->grantRole($userId, Role::AUTHOR);
    expect($authService->userHasRole($userId, Role::ADMIN))->toBeTrue();
    expect($authService->userHasRole($userId, Role::AUTHOR))->toBeTrue();

    // Revoke ADMIN
    $authService->revokeRole($userId, Role::ADMIN);
    expect($authService->userHasRole($userId, Role::ADMIN))->toBeFalse();
    expect($authService->userHasRole($userId, Role::AUTHOR))->toBeTrue();
});

test('multiple roles can be held simultaneously', function () {
    $authService = bootAuthLayer();

    $userId = $authService->register('multi-role@example.com', 'Password1!');

    // Grant multiple roles
    $authService->grantRole($userId, Role::ADMIN);
    $authService->grantRole($userId, Role::MODERATOR);
    $authService->grantRole($userId, Role::AUTHOR);

    expect($authService->userHasRole($userId, Role::ADMIN))->toBeTrue();
    expect($authService->userHasRole($userId, Role::MODERATOR))->toBeTrue();
    expect($authService->userHasRole($userId, Role::AUTHOR))->toBeTrue();

    // Revoke one, others should remain
    $authService->revokeRole($userId, Role::MODERATOR);

    expect($authService->userHasRole($userId, Role::ADMIN))->toBeTrue();
    expect($authService->userHasRole($userId, Role::MODERATOR))->toBeFalse();
    expect($authService->userHasRole($userId, Role::AUTHOR))->toBeTrue();
});
