<?php

declare(strict_types=1);

namespace Spora\Auth;

use Delight\Auth\Auth;

/**
 * Role and user-account administration flows. Extracted from {@see AuthService}
 * to keep that class under the S1448 (≤20 methods) limit (php:S1448).
 *
 * All methods are thin pass-throughs onto the underlying delight-im/auth admin
 * API. This collaborator exists purely to make the facade's responsibility
 * surface explicit and to keep every collaborator focused on a single concern.
 */
final class AuthRoleAdmin
{
    public function __construct(private readonly Auth $auth) {}

    /**
     * Grant a role to a user.
     */
    public function grantRole(int $userId, int $role): void
    {
        $this->auth->admin()->addRoleForUserById($userId, $role);
    }

    /**
     * Revoke a role from a user.
     */
    public function revokeRole(int $userId, int $role): void
    {
        $this->auth->admin()->removeRoleForUserById($userId, $role);
    }

    /**
     * Check if a user has a specific role.
     */
    public function userHasRole(int $userId, int $role): bool
    {
        return $this->auth->admin()->doesUserHaveRole($userId, $role);
    }

    /**
     * Delete a user by ID.
     */
    public function deleteUser(int $userId): void
    {
        $this->auth->admin()->deleteUserById($userId);
    }
}
