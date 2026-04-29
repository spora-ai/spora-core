<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Service interface for user and profile management.
 */
interface UserServiceInterface
{
    // ── User lifecycle ─────────────────────────────────────────────────────────

    /**
     * Get paginated users.
     *
     * @return array{data: list<array>, meta: array}
     */
    public function getUsers(int $page, int $perPage): array;

    /**
     * Get a single user by ID.
     *
     * @return array{user: array}|null
     */
    public function getUser(int $userId): ?array;

    /**
     * Update a user.
     *
     * @return array{user: array}|null
     */
    public function updateUser(int $userId, array $data): ?array;

    /**
     * Delete a user.
     *
     * @return bool True if deleted, false if not found
     */
    public function deleteUser(int $userId): bool;

    /**
     * Grant a role to a user.
     *
     * @return array{user: array}|null
     */
    public function grantRole(int $userId, string $role): ?array;

    /**
     * Revoke a role from a user.
     *
     * @return array{user: array}|null
     */
    public function revokeRole(int $userId, string $role): ?array;

    /**
     * List all roles for a user.
     *
     * @return list<string>
     */
    public function listRoles(int $userId): array;

    // ── Profile ───────────────────────────────────────────────────────────────

    /**
     * Get user profile (name, date_of_birth, about_me, height_cm, weight_kg).
     *
     * @return array{profile: array}|null
     */
    public function getProfile(int $userId): ?array;

    /**
     * Update user profile.
     *
     * @return array{profile: array}
     */
    public function updateProfile(int $userId, array $data): array;

    // ── Locations ────────────────────────────────────────────────────────────

    /**
     * Get all locations for a user.
     *
     * @return list<array>
     */
    public function getLocations(int $userId): array;

    /**
     * Create a new location for a user.
     *
     * @return array{location: array}
     */
    public function createLocation(int $userId, array $data): array;

    /**
     * Update a location.
     *
     * @return array{location: array}|null
     */
    public function updateLocation(int $locationId, int $userId, array $data): ?array;

    /**
     * Delete a location.
     *
     * @return bool True if deleted, false if not found
     */
    public function deleteLocation(int $locationId, int $userId): bool;
}
