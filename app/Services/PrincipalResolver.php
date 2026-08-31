<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use LogicException;
use Spora\Models\Agent;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Spora\Models\Task;

/**
 * Single seam for translating between agents, principals, and users.
 *
 * Replaces the old `$agent->user_id` access pattern at the 86+ consumer
 * sites in the codebase. Plugins must never read ownership directly off an
 * Agent — they go through this service, which is the public API for
 * "who is the user context for this agent?" in the principals-and-groups
 * model.
 *
 * Concurrency: every read-only helper issues a fresh query against the
 * default connection. Callers that mutate (transferAgent, etc.) take a
 * `lockForUpdate` inside their own transaction.
 */
final class PrincipalResolver
{
    /**
     * The user whose settings pay for this agent — the group's first user
     * with role `owner` if the principal is a group, otherwise the
     * principal's `user_id`. Returns `null` if the principal type is
     * unrecognised (which the {@see Principal} model precludes at write
     * time but a stale row could produce).
     */
    public function ownerUserId(int $principalId): ?int
    {
        $principal = Principal::find($principalId);
        if ($principal === null) {
            return null;
        }

        return match ($principal->type) {
            Principal::TYPE_USER  => (int) $principal->user_id,
            Principal::TYPE_GROUP => $this->groupOwnerUserId((int) $principal->group_id),
            default               => null,
        };
    }

    private function groupOwnerUserId(int $groupId): ?int
    {
        $ownerUserId = Capsule::table('group_memberships')
            ->where('group_id', $groupId)
            ->where('role', GroupMembership::ROLE_OWNER)
            ->orderBy('id')
            ->value('user_id');

        return $ownerUserId !== null ? (int) $ownerUserId : null;
    }

    /**
     * The user who triggered the most recent task for this agent. Falls
     * back to the agent's owner if no task has run yet (cold agent) so
     * credential resolution has something concrete to use during the
     * initial execution.
     *
     * Source column is `tasks.trigger_user_id` (not `user_id`): the
     * post-0071 schema separates the immutable clicker attribution
     * (`trigger_user_id`) from the mutable ownership marker
     * (`principal_id`). Resolving credentials from `trigger_user_id`
     * preserves the original "clicker runs the tick" semantic even
     * after the agent is transferred to a new owner.
     */
    public function runnerUserId(int $agentId): ?int
    {
        $triggerUserId = Task::where('agent_id', $agentId)
            ->orderByDesc('id')
            ->value('trigger_user_id');

        if ($triggerUserId !== null) {
            return (int) $triggerUserId;
        }

        $principalId = Agent::where('id', $agentId)->value('principal_id');
        return $principalId !== null ? $this->ownerUserId((int) $principalId) : null;
    }

    /**
     * Resolve a user-id → user-principal-id. Used by writers that need
     * to populate `tasks.principal_id` from a caller-supplied user-id
     * (e.g. Orchestrator::start). Throws when no user-principal exists
     * — the post-0067 schema guarantees one per user via the
     * migration's bulk-insert + the `PrincipalService::ensureUserPrincipal()`
     * idempotent guard, so a miss means the caller forgot to materialise.
     */
    public function userPrincipalId(int $userId): int
    {
        $id = Principal::where('type', Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->value('id');

        if ($id === null) {
            throw new LogicException(
                "No user-principal exists for user {$userId} — call PrincipalService::ensureUserPrincipal() first.",
            );
        }
        return (int) $id;
    }

    /**
     * True iff the given user can see the agent. Visibility follows
     * principals: any principal a user can act as (own user-principal or
     * group-principals of their groups) confers visibility on agents the
     * principal owns. Admins see everything (the controller layer is
     * responsible for the admin check — this method only models the
     * principal membership axis).
     *
     * @param  int $agentId
     * @param  int $userId
     */
    public function isVisibleTo(int $agentId, int $userId): bool
    {
        $principalIds = $this->visiblePrincipalIds($userId);
        if ($principalIds === []) {
            return false;
        }

        return Agent::where('id', $agentId)
            ->whereIn('principal_id', $principalIds)
            ->exists();
    }

    /**
     * Principal IDs the user can act as: their own user-principal plus
     * group-principals for every group they belong to. Always returns at
     * least the caller's user-principal (which is auto-created if missing
     * via {@see PrincipalService::ensureUserPrincipal()}; callers that
     * want a list before that has run may see an empty entry).
     *
     * @return list<int>
     */
    public function visiblePrincipalIds(int $userId): array
    {
        $userPrincipalId = Principal::where('type', Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->value('id');

        if ($userPrincipalId === null) {
            return [];
        }

        $groupIds = GroupMembership::where('user_id', $userId)->pluck('group_id');
        $groupPrincipalIds = [];
        if ($groupIds->isNotEmpty()) {
            $groupPrincipalIds = Principal::where('type', Principal::TYPE_GROUP)
                ->whereIn('group_id', $groupIds)
                ->pluck('id')
                ->all();
        }

        return array_merge([(int) $userPrincipalId], $groupPrincipalIds);
    }

    /**
     * Bundle up the principal context for a tool execution. Resolves the
     * agent's principal once, then fills `ownerUserId` from the principal
     * and `runnerUserId` from the latest task. Either may be `null` for a
     * stale principal or a cold agent; callers fall back to defaults.
     */
    public function resolveForToolExecute(int $agentId): PrincipalContext
    {
        $agent = Agent::find($agentId);
        if ($agent === null) {
            return new PrincipalContext(0, Principal::TYPE_USER, null, null);
        }

        $principal = Principal::find((int) $agent->principal_id);
        if ($principal === null) {
            return new PrincipalContext((int) $agent->principal_id, Principal::TYPE_USER, null, null);
        }

        return new PrincipalContext(
            principalId: (int) $principal->id,
            type: (string) $principal->type,
            ownerUserId: $this->ownerUserId((int) $principal->id),
            runnerUserId: $this->runnerUserId($agentId),
        );
    }

    /**
     * Whether the user is the principal's owner (or an `owner` of the
     * group that owns a group-principal). Used by transfer and delete
     * gates where "owner-equivalent" rights are required (≠ admin: admins
     * can edit group membership but cannot reassign the group's
     * principal-bound agents).
     */
    public function isPrincipalOwner(int $userId, int $principalId): bool
    {
        $principal = Principal::find($principalId);
        if ($principal === null) {
            return false;
        }

        if ($principal->type === Principal::TYPE_USER) {
            return (int) $principal->user_id === $userId;
        }

        return GroupMembership::where('group_id', $principal->group_id)
            ->where('user_id', $userId)
            ->where('role', GroupMembership::ROLE_OWNER)
            ->exists();
    }
}
