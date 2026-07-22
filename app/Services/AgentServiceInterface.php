<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;

/**
 * Service interface for agent lifecycle + flag management.
 *
 * Tool enablement, per-agent settings overrides, and per-operation overrides
 * moved to {@see AgentToolSettingsServiceInterface} so this service stays
 * under SonarCloud's 20-method-per-class ceiling (S1448). Consumers that
 * only touch the lifecycle / flag surface (AgentController) keep depending
 * on this interface; consumers that touch tools depend on the narrower one.
 */
interface AgentServiceInterface
{
    // ── Agent lifecycle ─────────────────────────────────────────────────────────

    /**
     * @return list<array> Agent resource arrays for a user
     */
    public function getAgentsForUser(int $userId): array;

    public function createAgent(int $userId, array $data): Agent;

    public function getAgent(int $agentId, int $userId): ?Agent;

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

    // ── Flag setters ────────────────────────────────────────────────────────────

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
}
