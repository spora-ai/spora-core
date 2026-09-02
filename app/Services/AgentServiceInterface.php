<?php

declare(strict_types=1);

namespace Spora\Services;

use RuntimeException;
use Spora\Models\Agent;

/**
 * Service interface for agent lifecycle + flag management.
 *
 * Tool enablement, per-agent settings overrides, and per-operation overrides
 * moved to {@see AgentToolSettingsServiceInterface} so this service stays
 * under SonarCloud's 20-method-per-class ceiling (S1448). Consumers that
 * only touch the lifecycle / flag surface (AgentController) keep depending
 * on this interface; consumers that touch tools depend on the narrower one.
 *
 * Principals-and-groups (migration 0067) re-keyed the ownership column from
 * `agents.user_id` to `agents.principal_id`. Every "for $userId" method now
 * matches on the union of principals the user can act as (own user-principal
 * + every group-principal of which they are a member), so a single API call
 * covers both personal and shared agents without callers having to know the
 * principal model exists.
 */
interface AgentServiceInterface
{
    /**
     * @return list<array> Agent resource arrays for a user
     */
    public function getAgentsForUser(int $userId, ?array $principalIds = null): array;

    /**
     * Create an agent. The third parameter selects the owning principal:
     * null defaults to the caller's user-principal (auto-created if
     * missing); admins passing a group-principal id create on behalf of
     * the group.
     */
    public function createAgent(int $userId, array $data, ?int $principalId = null): Agent;

    /**
     * Look up an agent by id, with visibility gated on the calling user
     * being able to act as one of the agent's principals. Returns null
     * for agents the user cannot see.
     */
    public function getAgent(int $agentId, int $userId): ?Agent;

    /**
     * Edit allowed fields on a user-visible agent. Returns null when the
     * agent is not visible to the calling user.
     */
    public function updateAgent(int $agentId, int $userId, array $data): ?Agent;

    /**
     * Update an agent's editable fields without a user-ownership check.
     *
     * Intended for in-tool callers (AgentTool) where the orchestrator has
     * already pinned the agent id to the calling agent. Callers outside
     * that context must use {@see self::updateAgent()} instead so the
     * standard user-ownership guard is applied.
     */
    public function updateAgentByAgentId(int $agentId, array $data): ?Agent;

    /**
     * Look up an agent by id without a user-ownership check.
     *
     * Same context as {@see self::updateAgentByAgentId()}: intended for
     * in-tool callers where the orchestrator has already pinned the agent
     * id. Operator-facing code must keep using {@see self::getAgent()}
     * with a userId so the standard ownership guard applies.
     */
    public function getAgentByAgentId(int $agentId): ?Agent;

    public function deleteAgent(int $agentId, int $userId): bool;

    /**
     * Pin or unpin an agent for the given user.
     *
     * Parameter order is (int $userId, int $agentId, bool $value) — read as
     * "user N sets agent M's pinned flag to V". This differs from the rest
     * of the service which takes (int $agentId, int $userId, ...).
     *
     * @throws Exceptions\AgentNotFoundException If the agent does not exist or is not owned by $userId
     */
    public function setPinned(int $userId, int $agentId, bool $pinned): Agent;

    /**
     * Archive or unarchive an agent for the given user.
     *
     * Parameter order is (int $userId, int $agentId, bool $value) — see
     * setPinned() for the rationale on the deliberate flip away from the
     * service-wide (int $agentId, int $userId, ...) convention.
     *
     * @throws Exceptions\AgentNotFoundException If the agent does not exist or is not owned by $userId
     */
    public function setArchived(int $userId, int $agentId, bool $archived): Agent;

    /**
     * Transfer an agent's ownership to a different principal. The caller
     * must control both source and target principal (admin/owner of the
     * source, admin/owner of the target, or owner of the target when the
     * target is the caller's user-principal). Admins skip the source side
     * of the gate.
     *
     * @throws Exceptions\UnauthorizedTransferException When the caller is not authorised
     * @throws RuntimeException When the agent or target principal does not exist
     */
    public function transferAgent(int $agentId, int $targetPrincipalId, int $callerUserId): Agent;
}
