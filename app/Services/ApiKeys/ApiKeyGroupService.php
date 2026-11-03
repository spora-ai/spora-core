<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use Spora\Models\Group;
use Spora\Services\GroupDetailResource;
use Spora\Services\GroupService;
use Spora\Services\PrincipalService;
use Spora\Services\ProfilePictures\GroupPictureService;

/**
 * Group CRUD for the global API surface.
 *
 * A thin wrapper over {@see GroupService}, so the RBAC rules, the
 * last-owner guard and the "the group still owns agents" pre-flight stay
 * where they are — one implementation, one set of rules.
 */
final class ApiKeyGroupService
{
    public function __construct(
        private readonly GroupService $groupService,
        private readonly PrincipalService $principalService,
        private readonly ?GroupPictureService $pictureService = null,
    ) {}

    /**
     * Every group in the install.
     *
     * @return list<Group>
     */
    public function listGroups(): array
    {
        return Group::orderBy('name')->get()->all();
    }

    /**
     * Create a group owned by the user who minted the key.
     *
     * A traceable human on every group is what keeps the last-owner guard
     * meaningful: a group with no owner cannot be administered by anybody.
     */
    public function create(int $ownerUserId, string $name, ?string $description = null): Group
    {
        return $this->groupService->createGroup($ownerUserId, $name, $description);
    }

    /**
     * @param array{name?: string, description?: ?string} $changes
     */
    public function update(int $groupId, array $changes): Group
    {
        return $this->groupService->updateGroup($groupId, $changes);
    }

    /**
     * Delete a group.
     *
     * The service refuses while the group still owns agents; the caller
     * surfaces that as a 409.
     */
    public function delete(int $groupId, int $callerUserId): void
    {
        $this->groupService->deleteGroup($groupId, $callerUserId, true);
    }

    /**
     * The group in the shape the admin API returns.
     *
     * @return array<string, mixed>
     */
    public function present(Group $group, int $callerUserId): array
    {
        return GroupDetailResource::toArray(
            $group,
            $callerUserId,
            $this->principalService,
            $this->pictureService,
        );
    }
}
