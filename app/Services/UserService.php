<?php

declare(strict_types=1);

namespace Spora\Services;

use Delight\Auth\Role;
use Delight\Auth\Status;
use Illuminate\Support\Carbon;
use Spora\Models\Principal;
use Spora\Models\User;
use Spora\Services\UserPictures\UserPictureService;

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

    /**
     * `$userPictures` is nullable so a caller that does not care about the
     * avatar column can leave it out — `serializeUser()` skips the lookup
     * when it is null.
     */
    public function __construct(
        private readonly PrincipalService $principalService,
        private readonly ?UserPictureService $userPictures = null,
        private readonly PrincipalLocationService $locations = new PrincipalLocationService(),
    ) {}

    /**
     * Flat envelope (`users` + pagination keys at top level) — matches the
     * frontend `PaginatedUsers` type and avoids a `{data, meta}` wrapper that
     * the shared API client would auto-unwrap, dropping the meta block.
     */
    public function getUsers(int $page, int $perPage): array
    {
        $paginator = User::orderBy('id', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        $users = array_map(fn(User $u) => $this->serializeUser($u), $paginator->all());

        return [
            'users'        => $users,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
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

    public function getUserIdByEmail(string $email): ?int
    {
        $user = User::where('email', $email)->first();
        return $user !== null ? (int) $user->id : null;
    }

    public function updateUser(int $userId, array $data): ?array
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        if (array_key_exists('username', $data)) {
            $user->username = $data['username'] !== '' ? (string) $data['username'] : null;
        }

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'] !== '' ? (string) $data['name'] : null;
        }

        if (array_key_exists('suspended', $data)) {
            // The account state lives in `status`; the auth library keeps
            // `force_logout` as the revocation counter it compares against
            // the session, so it only ever moves forward. Suspending twice
            // therefore revokes the sessions opened in between.
            $suspended = (bool) $data['suspended'];
            $user->status = $suspended ? Status::SUSPENDED : Status::NORMAL;
            if ($suspended) {
                $user->force_logout = (int) $user->force_logout + 1;
            }
        }

        if (array_key_exists('verified', $data)) {
            $user->verified = $data['verified'] ? 1 : 0;
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

    public function getLocations(int $userId): array
    {
        $principalId = $this->principalIdFor($userId);
        if ($principalId === null) {
            return [];
        }

        return $this->locations->listFor($principalId);
    }

    public function createLocation(int $userId, array $data): array
    {
        $principal = $this->principalService->ensureUserPrincipal($userId);

        return $this->locations->create((int) $principal->id, $data);
    }

    public function updateLocation(int $locationId, int $userId, array $data): ?array
    {
        $principal = $this->principalService->ensureUserPrincipal($userId);

        return $this->locations->update($locationId, (int) $principal->id, $data);
    }

    public function deleteLocation(int $locationId, int $userId): bool
    {
        $principal = $this->principalService->ensureUserPrincipal($userId);

        return $this->locations->delete($locationId, (int) $principal->id);
    }

    /**
     * The user's own principal, without creating one — a read must never
     * write, and a user without a principal has no locations.
     */
    private function principalIdFor(int $userId): ?int
    {
        $id = Principal::where('type', Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

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
            'name'     => $user->name,
            'roles'    => $roles,
            'verified' => (bool) $user->verified,
            'suspended' => (int) $user->status === Status::SUSPENDED,
            'registered' => (int) $user->registered,
            'is_admin' => in_array('ADMIN', $roles, true),
            // Embedded so every `getUser`/`getUsers` consumer (admin
            // list, `/auth/me`, `/auth/login`) emits the picture in
            // the same shape without each caller re-fetching.
            'profile_picture' => $this->userPictures !== null
                ? $this->userPictures->toWireShape($this->userPictures->findForUser((int) $user->id))
                : null,
        ];
    }
}
