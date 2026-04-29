<?php

declare(strict_types=1);

namespace Spora\Services;

use Delight\Auth\Role;
use Illuminate\Support\Carbon;
use Spora\Models\User;
use Spora\Models\UserLocation;

/**
 * Service for user and profile management.
 * All DB access for User domain goes through this service.
 */
final class UserService implements UserServiceInterface
{
    private const ROLE_MAP = [
        'ADMIN'       => Role::ADMIN,
        'AUTHOR'      => Role::AUTHOR,
        'CONSULTANT'  => Role::CONSULTANT,
        'COORDINATOR' => Role::COORDINATOR,
        'MODERATOR'   => Role::MODERATOR,
    ];

    // ── User lifecycle ─────────────────────────────────────────────────────────

    public function getUsers(int $page, int $perPage): array
    {
        $paginator = User::orderBy('id', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        $users = array_map(fn(User $u) => $this->serializeUser($u), $paginator->all());

        return [
            'data' => $users,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }

    public function getUser(int $userId): ?array
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        return ['user' => $this->serializeUser($user)];
    }

    public function updateUser(int $userId, array $data): ?array
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        if (isset($data['username'])) {
            $user->username = (string) $data['username'];
        }

        if (isset($data['suspended'])) {
            $user->force_logout = $data['suspended'] ? 1 : 0;
        }

        $user->save();

        return ['user' => $this->serializeUser($user)];
    }

    public function deleteUser(int $userId): bool
    {
        $user = User::find($userId);
        if ($user === null) {
            return false;
        }

        $user->delete();

        return true;
    }

    public function grantRole(int $userId, string $role): ?array
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        $roleValue = self::ROLE_MAP[strtoupper($role)] ?? null;
        if ($roleValue === null) {
            return null;
        }

        $user->roles_mask = ($user->roles_mask | $roleValue);
        $user->save();

        return ['user' => $this->serializeUser($user)];
    }

    public function revokeRole(int $userId, string $role): ?array
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        $roleValue = self::ROLE_MAP[strtoupper($role)] ?? null;
        if ($roleValue === null) {
            return null;
        }

        $user->roles_mask = ($user->roles_mask & ~$roleValue);
        $user->save();

        return ['user' => $this->serializeUser($user)];
    }

    public function listRoles(int $userId): array
    {
        $user = User::find($userId);
        if ($user === null) {
            return [];
        }

        $roles = [];
        foreach (self::ROLE_MAP as $name => $value) {
            if ($user->hasRole($value)) {
                $roles[] = $name;
            }
        }

        return $roles;
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    public function getProfile(int $userId): ?array
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        return ['profile' => [
            'name'         => $user->name,
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'about_me'     => $user->about_me,
            'height_cm'    => $user->height_cm,
            'weight_kg'    => $user->weight_kg,
        ]];
    }

    public function updateProfile(int $userId, array $data): array
    {
        $user = User::find($userId);

        if (isset($data['name'])) {
            $user->name = trim((string) $data['name']) ?: null;
        }
        if (isset($data['date_of_birth'])) {
            $user->date_of_birth = $data['date_of_birth'] !== '' ? Carbon::parse($data['date_of_birth']) : null;
        }
        if (isset($data['about_me'])) {
            $user->about_me = trim((string) $data['about_me']) ?: null;
        }
        if (isset($data['height_cm'])) {
            $user->height_cm = $data['height_cm'] !== '' ? (float) $data['height_cm'] : null;
        }
        if (isset($data['weight_kg'])) {
            $user->weight_kg = $data['weight_kg'] !== '' ? (float) $data['weight_kg'] : null;
        }

        $user->save();

        return ['profile' => [
            'name'         => $user->name,
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'about_me'     => $user->about_me,
            'height_cm'    => $user->height_cm,
            'weight_kg'    => $user->weight_kg,
        ]];
    }

    // ── Locations ────────────────────────────────────────────────────────────

    public function getLocations(int $userId): array
    {
        $user = User::find($userId);
        if ($user === null) {
            return [];
        }

        $locations = $user->locations ?? collect();

        return $locations->map(fn(UserLocation $loc) => [
            'id'        => $loc->id,
            'name'      => $loc->name,
            'address'   => $loc->address,
            'is_default' => $loc->is_default,
        ])->values()->toArray();
    }

    public function createLocation(int $userId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        $isDefault = ($data['is_default'] ?? false) === true;

        if ($isDefault) {
            UserLocation::where('user_id', $userId)->update(['is_default' => false]);
        }

        $location = UserLocation::create([
            'user_id'    => $userId,
            'name'       => $name,
            'address'    => $address,
            'is_default' => $isDefault,
        ]);

        return ['location' => [
            'id'        => $location->id,
            'name'      => $location->name,
            'address'   => $location->address,
            'is_default' => $location->is_default,
        ]];
    }

    public function updateLocation(int $locationId, int $userId, array $data): ?array
    {
        $location = UserLocation::where('id', $locationId)->where('user_id', $userId)->first();
        if ($location === null) {
            return null;
        }

        if (isset($data['name'])) {
            $location->name = trim((string) $data['name']);
        }
        if (isset($data['address'])) {
            $location->address = trim((string) $data['address']);
        }
        if (isset($data['is_default'])) {
            if ($data['is_default'] === true) {
                UserLocation::where('user_id', $userId)->where('id', '!=', $locationId)->update(['is_default' => false]);
            }
            $location->is_default = $data['is_default'] === true;
        }

        $location->save();

        return ['location' => [
            'id'        => $location->id,
            'name'      => $location->name,
            'address'   => $location->address,
            'is_default' => $location->is_default,
        ]];
    }

    public function deleteLocation(int $locationId, int $userId): bool
    {
        $location = UserLocation::where('id', $locationId)->where('user_id', $userId)->first();
        if ($location === null) {
            return false;
        }

        $location->delete();

        return true;
    }

    // ── Private helpers ─────────────────────────────────────────────────────────

    private function serializeUser(User $user): array
    {
        $roles = [];
        foreach (self::ROLE_MAP as $name => $value) {
            if ($user->hasRole($value)) {
                $roles[] = $name;
            }
        }

        return [
            'id'       => (int) $user->id,
            'email'    => $user->email,
            'username' => $user->username,
            'roles'    => $roles,
            'registered' => (int) $user->registered,
        ];
    }
}
