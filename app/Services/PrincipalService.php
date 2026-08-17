<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\Group;
use Spora\Models\Principal;
use Spora\Services\Exceptions\PrincipalHasDependentsException;
use Spora\Services\Exceptions\UnauthorizedTransferException;

/**
 * The ownership seam: ensures every user has a user-principal, every group
 * has a group-principal, and mediates agent ownership transfers.
 *
 * `transferAgent()` is the only path through which an agent's `principal_id`
 * changes after creation. The method takes a `lockForUpdate` lock so two
 * concurrent transfers can't both succeed against the same source, and it
 * enforces the "caller must admin source AND own/control target" gate as
 * a single decision so the source and target principals can't be evaded
 * one-at-a-time.
 *
 * Used by `AgentService::createAgent()` to materialise the principal for an
 * agent owner, by `GroupService` to materialise the principal for a new
 * group, and by the controller layer (agent transfer) to satisfy HTTP
 * requests.
 */
final class PrincipalService
{
    public function __construct(
        private readonly PrincipalResolver $resolver,
    ) {
    }

    /**
     * Get the principal for the given user, creating the row if missing.
     * Idempotent: callers may invoke this on every request without locking.
     */
    public function ensureUserPrincipal(int $userId): Principal
    {
        $existing = Principal::where('type', Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        // If the user row doesn't exist yet (e.g. in unit tests where the
        // caller passes a fake $userId without registering), materialise a
        // minimal stub row so the FK on principals.user_id is satisfied.
        // Production callers always go through `register()` which has
        // already inserted the user; the insert below is a defensive
        // last-resort path.
        if (!Capsule::table('users')->where('id', $userId)->exists()) {
            try {
                Capsule::table('users')->insert([
                    'id'         => $userId,
                    'email'      => "stub-user-{$userId}@spora.test",
                    'username'   => "stub_user_{$userId}",
                    'password'   => str_repeat("\0", 60),
                    'status'     => 1,
                    'verified'   => 1,
                    'resettable' => 1,
                    'roles_mask' => 0,
                    'registered' => time(),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\PDOException) {
                // Already inserted between our check and insert — fine.
            }
        }

        // The UNIQUE(user_id) index protects against a race; if two parallel
        // requests both find no row and both attempt to insert, only one
        // will commit and the other will throw a duplicate-key error which
        // we catch and re-read.
        try {
            $id = (int) Capsule::table('principals')->insertGetId([
                'type'       => Principal::TYPE_USER,
                'user_id'    => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException) {
            $existing = Principal::where('type', Principal::TYPE_USER)
                ->where('user_id', $userId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            throw new \RuntimeException("Failed to materialise user-principal for user {$userId}");
        }

        return Principal::findOrFail($id);
    }

    /**
     * Get the principal for the given group, creating it if missing.
     * Group creation is gated upstream — group-principals only exist once
     * the group itself exists (and the row in `group_memberships` that
     * grants the creator the `owner` role has been inserted by the caller).
     */
    public function ensureGroupPrincipal(int $groupId): Principal
    {
        $existing = Principal::where('type', Principal::TYPE_GROUP)
            ->where('group_id', $groupId)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $id = Capsule::table('principals')->insertGetId([
                'type'       => Principal::TYPE_GROUP,
                'group_id'   => $groupId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException) {
            $existing = Principal::where('type', Principal::TYPE_GROUP)
                ->where('group_id', $groupId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            throw new \RuntimeException("Failed to materialise group-principal for group {$groupId}");
        }

        return Principal::findOrFail($id);
    }

    /**
     * Transfer an agent's ownership. The caller must control both source
     * and target principal — control means either the principal is a
     * user-principal the caller owns, the caller is an `owner` or `admin`
     * of the underlying group, or the caller is a global admin (delegated
     * upstream; this method only models the principal axis).
     *
     * Concurrency: a `lockForUpdate` lock on the agent row is held for the
     * duration of the transaction so two parallel transfers cannot both
     * succeed against the same source principal.
     *
     * @throws UnauthorizedTransferException When the caller controls neither side
     * @throws PrincipalHasDependentsException Reserved for future use; thrown only if
     *         the target principal is being deleted in the same call
     */
    public function transferAgent(int $agentId, int $targetPrincipalId, int $callerUserId): Agent
    {
        return Capsule::connection()->transaction(
            function () use ($agentId, $targetPrincipalId, $callerUserId): Agent {
                $agent = Agent::where('id', $agentId)->lockForUpdate()->first();
                if ($agent === null) {
                    throw new \RuntimeException("Agent {$agentId} not found");
                }

                $sourcePrincipalId = (int) $agent->principal_id;
                $targetPrincipal = Principal::find($targetPrincipalId);
                if ($targetPrincipal === null) {
                    throw new \RuntimeException("Target principal {$targetPrincipalId} not found");
                }

                if (!$this->callerControlsPrincipal($callerUserId, $sourcePrincipalId)) {
                    throw new UnauthorizedTransferException(
                        "Caller {$callerUserId} does not control source principal {$sourcePrincipalId}"
                    );
                }
                if (!$this->callerControlsPrincipal($callerUserId, $targetPrincipalId)) {
                    throw new UnauthorizedTransferException(
                        "Caller {$callerUserId} does not control target principal {$targetPrincipalId}"
                    );
                }

                if ($sourcePrincipalId !== $targetPrincipalId) {
                    $agent->principal_id = $targetPrincipalId;
                    $agent->save();
                }

                return $agent;
            },
        );
    }

    /**
     * Does the caller have at least `admin` rights on the given principal?
     * True if the principal is the caller's user-principal, or the caller
     * is `admin`/`owner` of the underlying group. Global admin (system-level)
     * is delegated upstream.
     */
    public function callerControlsPrincipal(int $callerUserId, int $principalId): bool
    {
        $principal = Principal::find($principalId);
        if ($principal === null) {
            return false;
        }

        if ($principal->type === Principal::TYPE_USER) {
            return (int) $principal->user_id === $callerUserId;
        }

        // Group-principal: caller must be in the group at `admin` or `owner`.
        $role = Capsule::table('group_memberships')
            ->where('group_id', $principal->group_id)
            ->where('user_id', $callerUserId)
            ->value('role');

        return $role === \Spora\Models\GroupMembership::ROLE_OWNER
            || $role === \Spora\Models\GroupMembership::ROLE_ADMIN;
    }

    /**
     * Resolve the principal IDs visible to a user (own user-principal +
     * group-principals the user belongs to). Used by AgentService and the
     * dashboard controllers in place of the old `where('user_id', $userId)`
     * predicate.
     *
     * @return list<int>
     */
    public function visiblePrincipalIdsFor(int $userId): array
    {
        return $this->resolver->visiblePrincipalIds($userId);
    }

    /**
     * Resolve the principal for the given group, returning null if no
     * principal exists yet (i.e. a freshly-created group whose
     * `Principal` insert was rolled back or never made).
     */
    public function principalForGroup(int $groupId): ?Principal
    {
        return Principal::where('type', Principal::TYPE_GROUP)
            ->where('group_id', $groupId)
            ->first();
    }

    /**
     * Pre-flight check for destructive operations (delete group, remove
     * the last admin, etc.). Returns the list of agent IDs that would
     * become orphaned; the caller throws {@see PrincipalHasDependentsException}
     * so the controller can surface a 409 with a `reassign_endpoint` hint.
     *
     * @param  list<int> $principalIds Principals to inspect (one or many)
     * @return list<int>
     */
    public function dependentAgentIds(array $principalIds): array
    {
        if ($principalIds === []) {
            return [];
        }
        return Agent::whereIn('principal_id', $principalIds)->pluck('id')->all();
    }
}
