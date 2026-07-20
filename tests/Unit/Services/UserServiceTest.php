<?php

declare(strict_types=1);

use Spora\Models\User;
use Spora\Services\UserService;

defined('USER_SVC_TEST_PASSWORD') || define('USER_SVC_TEST_PASSWORD', 'Password1!');

function makeUserServiceWithUser(): array
{
    $service = new UserService();

    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($auth, "user-svc-{$seq}@example.com", USER_SVC_TEST_PASSWORD);

    return [$service, $userId];
}

describe('UserService::getUsers', function (): void {

    it('returns a paginated result', function (): void {
        $service = new UserService();
        $auth = bootAuthLayer();
        bootAuth($auth, 'paginated-1@example.com', USER_SVC_TEST_PASSWORD);
        bootAuth($auth, 'paginated-2@example.com', USER_SVC_TEST_PASSWORD);

        $result = $service->getUsers(1, 10);

        expect($result['users'])->toBeArray();
        expect($result['users'])->not->toBeEmpty();
        expect($result)->toHaveKeys(['current_page', 'last_page', 'per_page', 'total']);
        expect($result['per_page'])->toBe(10);
    });
});

describe('UserService::getUser / getUserIdByEmail', function (): void {

    it('returns the user resource when found', function (): void {
        [$service, $userId] = makeUserServiceWithUser();

        $result = $service->getUser($userId);
        expect($result)->not->toBeNull();
        expect($result['user']['id'])->toBe($userId);
    });

    it('returns null for an unknown id', function (): void {
        $service = new UserService();
        expect($service->getUser(999999))->toBeNull();
    });

    it('finds users by email', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        $email = User::find($userId)->email;
        expect($service->getUserIdByEmail($email))->toBe($userId);
    });

    it('returns null when looking up an unknown email', function (): void {
        $service = new UserService();
        expect($service->getUserIdByEmail('nobody@example.com'))->toBeNull();
    });
});

describe('UserService::updateUser / deleteUser', function (): void {

    it('updates the username and returns the user', function (): void {
        [$service, $userId] = makeUserServiceWithUser();

        $result = $service->updateUser($userId, ['username' => 'new-name']);
        expect($result['user']['username'])->toBe('new-name');
    });

    it('toggles verified and suspended flags', function (): void {
        [$service, $userId] = makeUserServiceWithUser();

        $service->updateUser($userId, ['verified' => true, 'suspended' => false]);
        $user = User::find($userId);
        expect((int) $user->verified)->toBe(1);
        expect((int) $user->force_logout)->toBe(0);
    });

    it('returns null when updating a non-existent user', function (): void {
        $service = new UserService();
        expect($service->updateUser(999999, ['username' => 'x']))->toBeNull();
    });

    it('deletes a user and returns true', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        expect($service->deleteUser($userId))->toBeTrue();
        expect(User::find($userId))->toBeNull();
    });

    it('returns false when deleting a non-existent user', function (): void {
        $service = new UserService();
        expect($service->deleteUser(999999))->toBeFalse();
    });
});

describe('UserService::grantRole / revokeRole / listRoles', function (): void {

    it('grants ADMIN role and reports it in listRoles', function (): void {
        [$service, $userId] = makeUserServiceWithUser();

        $result = $service->grantRole($userId, 'ADMIN');
        expect($result['user']['is_admin'])->toBeTrue();
        expect($service->listRoles($userId))->toContain('ADMIN');
    });

    it('grants multiple roles and reports all of them', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        $service->grantRole($userId, 'ADMIN');
        $service->grantRole($userId, 'AUTHOR');

        $roles = $service->listRoles($userId);
        expect($roles)->toContain('ADMIN', 'AUTHOR');
    });

    it('revokes a previously granted role', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        $service->grantRole($userId, 'ADMIN');
        $service->revokeRole($userId, 'ADMIN');

        $roles = $service->listRoles($userId);
        expect($roles)->not->toContain('ADMIN');
    });

    it('returns null when granting an unknown role', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        expect($service->grantRole($userId, 'NOT_A_ROLE'))->toBeNull();
    });

    it('returns null when granting a role to a missing user', function (): void {
        $service = new UserService();
        expect($service->grantRole(999999, 'ADMIN'))->toBeNull();
    });

    it('returns null when revoking from a missing user', function (): void {
        $service = new UserService();
        expect($service->revokeRole(999999, 'ADMIN'))->toBeNull();
    });

    it('returns an empty array from listRoles for a missing user', function (): void {
        $service = new UserService();
        expect($service->listRoles(999999))->toBe([]);
    });
});

describe('UserService::getProfile / updateProfile', function (): void {

    it('returns null when the user does not exist', function (): void {
        $service = new UserService();
        expect($service->getProfile(999999))->toBeNull();
    });

    it('updates the profile fields and returns the new values', function (): void {
        [$service, $userId] = makeUserServiceWithUser();

        $result = $service->updateProfile($userId, [
            'name'         => 'Fabian',
            'about_me'     => 'I build things',
            'height_cm'    => 180.5,
            'weight_kg'    => 75.0,
        ]);

        expect($result['profile']['name'])->toBe('Fabian');
        expect($result['profile']['about_me'])->toBe('I build things');
        expect($result['profile']['height_cm'])->toBe(180.5);
        expect($result['profile']['weight_kg'])->toBe(75.0);
    });

    it('parses the date_of_birth field', function (): void {
        [$service, $userId] = makeUserServiceWithUser();

        $result = $service->updateProfile($userId, [
            'date_of_birth' => '1990-05-15',
        ]);

        expect($result['profile']['date_of_birth'])->toBe('1990-05-15');
    });
});

describe('UserService locations', function (): void {

    it('returns an empty list when there are no locations', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        expect($service->getLocations($userId))->toBe([]);
    });

    it('creates a location', function (): void {
        [$service, $userId] = makeUserServiceWithUser();

        $result = $service->createLocation($userId, [
            'name'       => 'Home',
            'address'    => '123 Main St',
            'is_default' => true,
        ]);

        expect($result['location']['name'])->toBe('Home');
        expect($result['location']['is_default'])->toBeTrue();

        $locs = $service->getLocations($userId);
        expect($locs)->toHaveCount(1);
    });

    it('updates a location', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        $created = $service->createLocation($userId, [
            'name'    => 'Old',
            'address' => 'Old address',
        ]);

        $result = $service->updateLocation($created['location']['id'], $userId, [
            'name'    => 'New',
            'address' => 'New address',
        ]);

        expect($result['location']['name'])->toBe('New');
        expect($result['location']['address'])->toBe('New address');
    });

    it('returns null when updating a missing location', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        expect($service->updateLocation(9999, $userId, ['name' => 'x']))->toBeNull();
    });

    it('deletes a location and returns true', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        $created = $service->createLocation($userId, ['name' => 'X']);

        expect($service->deleteLocation($created['location']['id'], $userId))->toBeTrue();
        expect($service->getLocations($userId))->toBe([]);
    });

    it('returns false when deleting a missing location', function (): void {
        [$service, $userId] = makeUserServiceWithUser();
        expect($service->deleteLocation(9999, $userId))->toBeFalse();
    });
});
