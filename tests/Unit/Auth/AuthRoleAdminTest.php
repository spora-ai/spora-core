<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Spora\Auth\AuthRoleAdmin;

function bootRoleAdmin(): AuthRoleAdmin
{
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false /* throttling off */);

    return new AuthRoleAdmin($auth);
}

test('userHasRole returns false for a freshly registered user with no roles', function (): void {
    $admin = bootRoleAdmin();

    // Register through the underlying delight-im layer to keep this test
    // focused on AuthRoleAdmin and avoid coupling it to AuthService.
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false);
    $userId = (int) $auth->register('naked-user@example.com', 'ValidPass1!', null, null);

    expect($admin->userHasRole($userId, Role::ADMIN))->toBeFalse();
});

test('grantRole + userHasRole + revokeRole flow mutates role state as expected', function (): void {
    $admin = bootRoleAdmin();

    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false);
    $userId = (int) $auth->register('role-flow@example.com', 'ValidPass1!', null, null);

    expect($admin->userHasRole($userId, Role::ADMIN))->toBeFalse();

    $admin->grantRole($userId, Role::ADMIN);
    expect($admin->userHasRole($userId, Role::ADMIN))->toBeTrue();

    $admin->revokeRole($userId, Role::ADMIN);
    expect($admin->userHasRole($userId, Role::ADMIN))->toBeFalse();
});
